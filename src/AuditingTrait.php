<?php

namespace OwenIt\Auditing;

use App;
use Config;
use Request;

trait AuditingTrait
{
    /**
     * @var array
     */
    private $originalData = [];

    /**
     * @var array
     */
    private $updatedData = [];

    /**
     * @var array
     */
    private $dontKeep = [];

    /**
     * @var array
     */
    private $doKeep = [];

    /**
     * @var bool
     */
    private $updating = false;

    /**
     * @var array
     */
    protected $dirtyData = [];

    /**
     * Init auditing.
     */
    public static function bootAuditingTrait()
    {
        static::saving(function ($model) {
            $model->prepareAudit();
        });

        static::created(function ($model) {
            if ($model->isTypeAuditable('created')) {
                $model->auditCreation();
            }
        });

        static::saved(function ($model) {
            if ($model->isTypeAuditable('saved')) {
                $model->auditUpdate();
            }
        });

        static::deleted(function ($model) {
            if ($model->isTypeAuditable('deleted')) {
                $model->prepareAudit();
                $model->auditDeletion();
            }
        });
    }

    /**
     * Get list of logs.
     *
     * @return mixed
     */
    public function logs()
    {
        return $this->morphMany(Log::class, 'owner');
    }

    /**
     * Generates a list of the last $limit revisions made to any objects
     * of the class it is being called from.
     *
     * @param int    $limit
     * @param string $order
     *
     * @return mixed
     */
    public static function classLogHistory($limit = 100, $order = 'desc')
    {
        return Log::where('owner_type', get_called_class())
            ->orderBy('updated_at', $order)->limit($limit)->get();
    }

    /**
     * @param int    $limit
     * @param string $order
     *
     * @return mixed
     */
    public function logHistory($limit = 100, $order = 'desc')
    {
        return static::classLogHistory($limit, $order);
    }

    /**
     * Prepare audit model.
     */
    public function prepareAudit()
    {
        if ($this->isAuditEnabled()) {
            $this->originalData = $this->original;
            $this->updatedData = $this->attributes;

            foreach ($this->updatedData as $key => $val) {
                if (gettype($val) == 'object' && !method_exists($val, '__toString')) {
                    unset($this->originalData[$key]);
                    unset($this->updatedData[$key]);
                    array_push($this->dontKeep, $key);
                }
            }

            // Dont keep log of
            $this->dontKeep = isset($this->dontKeepLogOf) ?
                $this->dontKeepLogOf + $this->dontKeep
                : $this->dontKeep;

            // Keep log of
            $this->doKeep = isset($this->keepLogOf) ?
                $this->keepLogOf + $this->doKeep
                : $this->doKeep;

            unset($this->attributes['dontKeepLogOf']);
            unset($this->attributes['keepLogOf']);

            // Get changed data
            $this->dirtyData = $this->getDirty();
            // Tells whether the record exists in the database
            $this->updating = $this->exists;
        }
    }

    /**
     * Audit creation.
     *
     * @return void
     */
    public function auditCreation()
    {
        if (isset($this->historyLimit) && $this->logHistory()->count() >= $this->historyLimit) {
            $LimitReached = true;
        } else {
            $LimitReached = false;
        }
        if (isset($this->logCleanup)) {
            $LogCleanup = $this->LogCleanup;
        } else {
            $LogCleanup = false;
        }

        if ((($this->isAuditEnabled())) && (!$LimitReached || $LogCleanup)) {
            $log = ['old_value' => null];
            $log['new_value'] = [];

            foreach ($this->updatedData as $key => $value) {
                if ($this->isAuditing($key)) {
                    $log['new_value'][$key] = $value;
                }
            }

            $this->audit($log, 'created');
        }
    }

    /**
     * Audit updated.
     *
     * @return void
     */
    public function auditUpdate()
    {
        if (isset($this->historyLimit) && $this->logHistory()->count() >= $this->historyLimit) {
            $LimitReached = true;
        } else {
            $LimitReached = false;
        }
        if (isset($this->logCleanup)) {
            $LogCleanup = $this->LogCleanup;
        } else {
            $LogCleanup = false;
        }

        if ((($this->isAuditEnabled()) && $this->updating) && (!$LimitReached || $LogCleanup)) {
            $changes_to_record = $this->changedAuditingFields();
            if (count($changes_to_record)) {
                foreach ($changes_to_record as $key => $change) {
                    $log['old_value'][$key] = array_get($this->originalData, $key);
                    $log['new_value'][$key] = array_get($this->updatedData, $key);
                }

                $this->audit($log, 'updated');
            }
        }
    }

    /**
     * Audit deletion.
     *
     * @return void
     */
    public function auditDeletion()
    {
        if (isset($this->historyLimit) && $this->logHistory()->count() >= $this->historyLimit) {
            $LimitReached = true;
        } else {
            $LimitReached = false;
        }
        if (isset($this->logCleanup)) {
            $LogCleanup = $this->LogCleanup;
        } else {
            $LogCleanup = false;
        }

        if ((($this->isAuditEnabled()) && $this->isAuditing('deleted_at')) && (!$LimitReached || $LogCleanup)) {
            $log = ['new_value' => null];

            foreach ($this->updatedData as $key => $value) {
                if ($this->isAuditing($key)) {
                    $log['old_value'][$key] = $value;
                }
            }

            $this->audit($log, 'deleted');
        }
    }

    /**
     * Audit model.
     *
     * @return Log
     */
    public function audit(array $log, $type)
    {
        $logAuditing = [
            'old_value'   => $this->asJson($log['old_value']),
            'new_value'   => $this->asJson($log['new_value']),
            'owner_type'  => get_class($this),
            'owner_id'    => $this->getKey(),
            'user_id'     => $this->getUserId(),
            'type'        => $type,
            'route'       => $this->getCurrentRoute(),
            'ip'          => $this->getIpAddress(),
            'created_at'  => $this->freshTimestamp(),
            'updated_at'  => $this->freshTimestamp(),
        ];

        return $this->saveAudit($logAuditing);
    }

    /**
     * Save Audit.
     *
     * @param array $fields
     *
     * @return Log
     */
    protected function saveAudit(array $fields)
    {
        return Log::insert($fields);
    }

    /**
     * Get user id.
     *
     * @return null
     */
    protected function getUserId()
    {
        try {
            if (\Auth::check()) {
                return \Auth::user()->getAuthIdentifier();
            }
        } catch (\Exception $e) {
            return;
        }
    }

    /**
     * Get the current request's route if available.
     *
     * @return string
     */
    protected function getCurrentRoute()
    {
        if (App::runningInConsole()) {
            return 'console';
        }

        return Request::route()->getName() ?: Request::route()->getUri();
    }

    /**
     * Get IP Address.
     *
     * @return mixed
     */
    public function getIpAddress()
    {
        return Request::ip();
    }

    /**
     * Fields Changed.
     *
     * @return array
     */
    private function changedAuditingFields()
    {
        $changes_to_record = [];
        foreach ($this->dirtyData as $key => $value) {
            if ($this->isAuditing($key) && !is_array($value)) {
                // Check whether the current value is difetente the original value
                if (!isset($this->originalData[$key]) || $this->originalData[$key] != $this->updatedData[$key]) {
                    $changes_to_record[$key] = $value;
                }
            } else {
                unset($this->updatedData[$key]);
                unset($this->originalData[$key]);
            }
        }

        return $changes_to_record;
    }

    /**
     * Is Auditing?
     *
     * @param $key
     *
     * @return bool
     */
    private function isAuditing($key)
    {
        // Checks if the field is in the collection of auditable
        if (isset($this->doKeep) && in_array($key, $this->doKeep)) {
            return true;
        }

        // Checks if the field is in the collection of non-auditable
        if (isset($this->dontKeep) && in_array($key, $this->dontKeep)) {
            return false;
        }

        // Checks whether the auditable list is clean
        return empty($this->doKeep);
    }

    /**
     * Is Auditing enabled?
     *
     * @return bool
     */
    private function isAuditEnabled()
    {
        // Check that the model has audit enabled and also check that we aren't running in cosole or that we want to log console too.
        if ((!isset($this->auditEnabled) || $this->auditEnabled) && (!App::runningInConsole() || Config::get('auditing.audit_console'))) {
            return true;
        }

        return false;
    }

    /**
     * Idenfiable name.
     *
     * @return mixed
     */
    public function identifiableName()
    {
        return $this->getKey();
    }

    /**
     * Verify is type auditable.
     *
     * @param $key
     *
     * @return bool
     */
    public function isTypeAuditable($key)
    {
        $auditableTypes = isset($this->auditableTypes)
                          ? $this->auditableTypes
                          : ['created', 'saved', 'deleted'];

        // Checks if the type is in the collection of type-auditable
        if (in_array($key, $auditableTypes)) {
            return true;
        }
    }
}
