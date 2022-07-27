<?php

namespace CasbinAdapter\Etcd;

use Casbin\Model\Model;
use Casbin\Exceptions\CasbinException;
use Casbin\Persist\Adapter as AdapterContract;
use Casbin\Persist\AdapterHelper;
use Casbin\Persist\FilteredAdapter as FilteredAdapterContract;
use Casbin\Persist\Adapters\Filter;
use Casbin\Exceptions\InvalidFilterTypeException;
use Casbin\Persist\BatchAdapter as BatchAdapterContract;
use Casbin\Persist\UpdatableAdapter as UpdatableAdapterContract;

class Adapter implements AdapterContract, FilteredAdapterContract, BatchAdapterContract, UpdatableAdapterContract
{
    use AdapterHelper;

    const KV_PUT = 'kv/put';

    const KV_RANGE = 'kv/range';

    const KV_DELETERANGE = 'kv/deleterange';

    protected $server;

    protected $version;

    protected $curl;

    public function __construct(string $server = '127.0.0.1:2379', string $version = 'v3')
    {
        $this->server = $server;
        $this->version = $version;

        if ($this->curl = curl_init())
        {
            curl_setopt($this->curl, CURLOPT_POST, 1);
            curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
        }
        else
        {
            throw new \RuntimeException("Create curl handle ERROR");
        }
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    public function savePolicyLine($ptype, array $rule)
    {
        if ($this->version == 'v3')
        {
            curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/' . $this->KV_PUT);
            foreach ($rule as $key => $value)
            {
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode(['key' => base64_encode($key), 'value' => base64_encode($ptype) . '/', base64_encode($value)]));
                curl_exec($this->curl);
            }
        }
        else if ($this->version == 'v2')
        {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'PUT');
            foreach ($rule as $key => $value)
            {
                curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/keys'. '/' . $key);
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, 'value=' . $value);
                curl_exec($this->curl);
            }
        }
    }

    /**
     * loads all policy rules from the storage.
     *
     * @param Model $model
     */
    public function loadPolicy(Model $model): void
    {
        if ($this->version == 'v3')
        {
            curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/' . $this->KV_RANGE);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode(['key' => base64_encode('ptype')]));
            $rows = json_decode(curl_exec($this->curl));
        }
        else if ($this->version == 'v2')
        {
            curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/keys'. '/ptype');
            $rows = json_decode(curl_exec($this->curl));
        }
        foreach ($rows as $row)
        {
            $this->loadPolicyLine($row, $model);
        }
    }

    /**
     * saves all policy rules to the storage.
     *
     * @param Model $model
     */
    public function savePolicy(Model $model): void
    {
        foreach ($model['p'] as $ptype => $ast)
        {
            foreach ($ast->policy as $rule)
            {
                $this->savePolicyLine($ptype, $rule);
            }
        }

        foreach ($model['g'] as $ptype => $ast)
        {
            foreach ($ast->policy as $rule)
            {
                $this->savePolicyLine($ptype, $rule);
            }
        }
    }

    /**
     * adds a policy rule to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array  $rule
     */
    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        $this->savePolicyLine($ptype, $rule);
    }

    /**
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array  $rule
     */
    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        if ($this->version == 'v3')
        {
            curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/' . $this->KV_DELETERANGE);
            foreach ($rule as $key => $value)
            {
                curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode(['key' => base64_encode($key)]));
                curl_exec($this->curl);
            }
        }
        else if ($this->version == 'v2')
        {
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
            foreach ($rule as $key => $value)
            {
                curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/keys'. '/' . $key);
                curl_exec($this->curl);
            }
        }
    }

    public function removePolicies(string $sec, string $ptype, array $rules): void
    {
        try
        {
            foreach ($rules as $rule)
            {
                $this->removePolicy($sec, $ptype, $rule);
            }
        }
        catch (\Throwable $e)
        {
            throw $e;
        }
    }

    /**
     * Updates a policy rule from storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[] $oldRule
     * @param string[] $newPolicy
     */
    public function updatePolicy(string $sec, string $ptype, array $oldRule, array $newPolicy): void
    {
        $where['ptype'] = $ptype;
        $condition[] = 'ptype = :ptype';
        curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/' . $this->KV_PUT);
        
        foreach ($oldRule as $key => $value)
        {
            $placeholder = "w" . strval($key);
            $where['w' . strval($key)] = $value;
            $condition[] = 'v' . strval($key) . ' = :' . $placeholder;
        }

        $update = [];
        foreach ($newPolicy as $key => $value)
        {
            $placeholder = "s" . strval($key);
            $updateValue["$placeholder"] = $value;
            $update[] = 'v' . strval($key) . ' = :' . $placeholder;
        }
        
        if ($this->version == 'v3')
        {
            curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/' . $this->KV_PUT);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode(['key'=>base64_encode($key), 'value'=>base64_encode($value)]));
            curl_exec($this->curl);
        }
        else if ($this->version == 'v2')
        {
            curl_setopt($this->curl, CURLOPT_URL, $this->server . '/' . $this->version . '/keys'. '/' . $key);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, 'value=' . $value);
            curl_exec($this->curl);
        }
    }

    /**
     * UpdatePolicies updates some policy rules to storage, like db, redis.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $oldRules
     * @param string[][] $newRules
     * @return void
     */
    public function updatePolicies(string $sec, string $ptype, array $oldRules, array $newRules): void
    {
        try
        {
            foreach ($oldRules as $i => $oldRule)
            {
                $this->updatePolicy($sec, $ptype, $oldRule, $newRules[$i]);
            }
        }
        catch (\Throwable $e)
        {
            throw $e;
        }
    }
}
