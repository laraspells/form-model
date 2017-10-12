<?php

namespace LaraSpells\FormModel;

use Closure;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Storage;
use UnexpectedValueException;

class FormModel
{

    const KEY_SUBMIT_VALUE = 'submit_value';
    const KEY_RENDER_VALUE = 'render_value';

    protected static $defaultView = '';
    protected static $defaultViewData = [];
    protected static $defaultInputViews = [];

    protected $isCreate = false;
    protected $request;
    protected $urlAction = '';
    protected $view = '';
    protected $viewData = [];
    protected $fields = [];
    protected $rulesCreate = [];
    protected $rulesUpdate = [];
    protected $scripts = [];
    protected $styles = [];
    protected $inputViews = [];

    protected $relations = [];

    protected $formDataResolver;
    protected $requestDataResolver;
    protected $beforeSave;
    protected $beforeSaveRelation;


    protected $uploadedFiles = [];

    public function __construct(Model $model, array $fields)
    {
        $this->model = $model;
        $this->isCreate = !$model->exists;
        $this->fields = $this->validateAndResolveFields($fields);
        $this->withView(static::getDefaultView(), static::getDefaultViewData());
        $this->withInputViews(static::getDefaultInputViews());
    }

    public static function make(Model $model, array $fields)
    {
        return new static($model, $fields);
    }

    public static function setDefaultView($view, array $data = [])
    {
        static::$defaultView = $view;
        static::$defaultViewData = $data;
    }

    public static function setDefaultInputViews(array $inputViews)
    {
        static::$defaultInputViews = $inputViews;
    }

    public static function getDefaultView()
    {
        return static::$defaultView;
    }

    public static function getDefaultViewData()
    {
        return static::$defaultViewData;
    }

    public static function getDefaultInputViews()
    {
        return static::$defaultInputViews;
    }

    public function withRules(array $rulesCreate, array $rulesUpdate = [])
    {
        $args = func_get_args();
        if (count($args) === 1) {
            $rulesUpdate = $rulesCreate;
        }

        $this->rulesCreate = $rulesCreate;
        $this->rulesUpdate = $rulesUpdate;

        return $this;
    }

    public function withInputViews(array $inputViews)
    {
        $this->inputViews = array_merge($this->inputViews, $inputViews);
        return $this;
    }

    public function withMany($relationKey, $data, array $fields)
    {
        if (is_string($data)) {
            $data = ['label' => $data];
        } elseif(!is_array($data)) {
            throw new InvalidArgumentException("Second argument 'withMany' method only accept string or array.");
        }

        $this->validateRelationKey($relationKey);
        $relationField = array_merge(['exists' => true], $data, ['fields' => $fields]);

        $this->fields[$relationKey] = $this->validateAndResolveRelationField($relationKey, $relationField);
        return $this;
    }

    public function withView($view, array $data = [])
    {
        $this->view = $view;
        $this->withViewData($data);
        return $this;
    }

    public function withViewData($key, $value = null)
    {
        $args = func_get_args();
        if (is_array($key) AND count($args) === 1) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }
        return $this;
    }

    public function withAction($urlAction)
    {
        $this->urlAction = $urlAction;
        return $this;
    }

    public function withCss($css)
    {
        $this->styles = array_merge($this->styles, (array) $css);
        return $this;
    }

    public function withJs($js)
    {
        $this->scripts = array_merge($this->scripts, (array) $js);
        return $this;
    }

    public function withRequest(Request $request)
    {
        $this->request = $request;
    }

    public function resolveRequestData(Closure $resolver)
    {
        $this->requestDataResolver = $resolver;
        return $this;
    }

    public function resolveFormData(Closure $resolver)
    {
        $this->formDataResolver = $resolver;
        return $this;
    }

    public function render($view = null, array $otherData = [])
    {
        if ($view) $this->withView($view);
        $view = $this->getView();
        $data = $this->resolveViewData($otherData);
        return view($view, $data)->render();
    }

    public function submit(Request $request)
    {
        DB::beginTransaction();
        $this->withRequest($request);
        try {
            if ($this->isCreate()) {
                $this->processCreate();
            } else {
                $this->processUpdate();
            }
            DB::commit();
            $this->commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            $this->rollback();
            throw $e;
        }
    }

    public function isCreate()
    {
        return $this->isCreate;
    }

    public function isUpdate()
    {
        return !$this->isCreate();
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function getExistsFields()
    {
        return array_filter($this->getFields(), function($field) {
            return $field['exists'];
        });
    }

    public function getExistsSingleFields()
    {
        $fields = [];
        foreach($this->fields as $key => $field) {
            if ($field['exists'] AND !$this->isFieldRelation($key, $field)) {
                $fields[$key] = $field;
            }
        }
        return $fields;
    }

    public function getField($key)
    {
        return array_get($this->fields, $key);
    }

    public function getRulesCreate()
    {
        return $this->rulesCreate;
    }

    public function getRulesUpdate()
    {
        return $this->rulesUpdate;
    }

    public function getRules()
    {
        $rules = $this->isCreate() ? $this->getRulesCreate() : $this->getRulesUpdate();

        // Merge with (exists) fields rules
        foreach ($this->fields as $key => $field) {
            if (!$field['exists']) continue;

            if (!$this->isFieldRelation($key, $field)) {
                $rules[$key] = isset($rules[$key]) ?
                    array_unique(array_merge($this->resolveRules($rules[$key]), $field['rules']))
                    : $field['rules'];
            } else {
                $relation = $this->getModel()->{$key}();
                foreach ($field['fields'] as $childKey => $childField) {
                    $ruleKey = ($relation instanceof HasMany) ? "{$key}.*.{$childKey}" : "{$key}.{$childKey}";
                    if (!$childField['exists']) {
                        if (isset($rules[$ruleKey])) unset($rules[$ruleKey]);
                        continue;
                    }

                    $rules[$ruleKey] = isset($rules[$ruleKey]) ?
                        array_unique(array_merge($this->resolveRules($rules[$ruleKey]), $childField['rules']))
                        : $childField['rules'];
                }
            }
        }

        return array_filter($rules, function($rule) {
            return !empty($rule);
        });
    }

    public function getRelationFields()
    {
        $fields = [];
        foreach($this->fields as $key => $field) {
            if ($this->isFieldRelation($key, $field)) {
                $fields[$key] = $field;
            }
        }
        return $fields;
    }

    public function getFieldRelation($relationKey)
    {
        return isset($this->fields[$relationKey]) ? $this->fields[$relationKey] : null;
    }

    public function getView()
    {
        return $this->view;
    }

    public function getInputViews()
    {
        return $this->inputViews;
    }

    public function getInputView($inputType, $default = null)
    {
        return isset($this->inputViews[$inputType]) ? $this->inputViews[$inputType] : $default;
    }

    public function getAction()
    {
        return $this->urlAction;
    }

    public function getViewData()
    {
        return $this->viewData;
    }

    public function getScripts()
    {
        return $this->scripts;
    }

    public function getStyles()
    {
        return $this->styles;
    }

    public function getRequest()
    {
        return $this->request ?: request();
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getBeforeSave()
    {
        return $this->beforeSave;
    }

    public function getBeforeSaveRelation($key)
    {
        return isset($this->beforeSaveRelation[$key]) ? $this->beforeSaveRelation[$key] : null;
    }

    public function beforeSave(Closure $callback)
    {
        $this->beforeSave = $callback;
        return $this;
    }

    public function getUploadableFields()
    {
        return array_filter($this->getFields(), function($field) {
            return $this->isUploadableField($field);
        });
    }

    public function hasUploadableField($includeRelations = true)
    {
        if (count($this->getUploadableFields())) {
            return true;
        }
        foreach ($this->getRelationFields() as $relation) {
            foreach ($relation['fields'] as $field) {
                if ($this->isUploadableField($field)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getInputableFields()
    {
        return array_filter($this->getInputableFields(), function($field) {
            return (isset($field['input']) AND false !== $field['exists']);
        });
    }

    public function getRenderValue($key)
    {
        $field = $this->getField($key);
        $value = ($field AND isset($field['default_value'])) ? $field['default_value'] : null;
        if ($this->isUpdate()) {
            $value = $this->getModel()->{$key};
            if ($field AND isset($field[static::KEY_RENDER_VALUE]) AND $field[static::KEY_RENDER_VALUE] instanceof Closure) {
                $resolver = $field[static::KEY_RENDER_VALUE]->bindTo($this);
                $value = $resolver($value);
            }
        }

        if ($this->hasFieldRelation($key)) {
            $value = (is_object($value) ? '' : $value);
        } else {
            $value = session($key) ?: old($key) ?: (is_object($value) ? '' : $value);
        }

        return $value;
    }

    public function getSubmitValues()
    {
        return $this->submitValues;
    }

    public function setSubmitValue($key, $value)
    {
        array_set($this->submitValues, $key, $value);
    }

    public function getSubmitValue($key)
    {
        return array_get($this->submitValues, $key);
    }

    public function getFieldHasManyValues($relationKey)
    {
        $request = $this->getRequest();
        $values = $request->get($relationKey);
        if (!is_array($values)) {
            return [];
        }

        // resolve values
        $formRelation = $this->getFieldRelation($relationKey);
        $relationFields = $formRelation['fields'];
        foreach ($values as $i => $value) {
            foreach ($value as $k => $v) {
                $resolver = (
                    isset($relationFields[$k])
                    AND isset($relationFields[$k][static::KEY_SUBMIT_VALUE])
                    AND $relationFields[$k][static::KEY_SUBMIT_VALUE] instanceof Closure
                )? $relationFields[$k][static::KEY_SUBMIT_VALUE] : null;

                if ($resolver) {
                    $resolver = $resolver->bindTo($this);
                    $values[$i][$k] = $resolver($v);
                }
            }
        }
        return $values;
    }

    public function getFieldHasOneValues($relationKey)
    {
        $request = $this->getRequest();
        return $request->get($relationKey);
    }

    public function beforeSaveRelation($relationKey, Closure $callback)
    {
        $this->checkHasFieldRelation($relationKey);
        $this->beforeSaveRelation[$relationKey] = $callback;
        return $this;
    }

    protected function processCreate()
    {
        $this->validateForm();
        $this->resolveSubmitValues();
        $this->processUploads();
        $this->fillModelValues();
        $this->runBeforeSave();
        $saved = $this->saveModel();
        $this->processRelations();
    }

    protected function processUpdate()
    {
        $this->validateForm();
        $this->resolveSubmitValues();
        $this->processUploads();
        $this->fillModelValues();
        $this->runBeforeSave();
        $saved = $this->saveModel();
        $this->processRelations();
    }

    protected function validateForm()
    {
        $request = $this->getRequest();
        $rules = $this->getRules();
        $request->validate($rules);
    }

    protected function resolveSubmitValues()
    {
        foreach ($this->fields as $key => $field) {
            $value = $this->getRequest()->get($key);
            if (isset($field[static::KEY_SUBMIT_VALUE]) AND $field[static::KEY_SUBMIT_VALUE] instanceof Closure) {
                $resolver = $field[static::KEY_SUBMIT_VALUE]->bindTo($this);
                $value = $resolver($value);
                $this->setSubmitValue($key, $value);
            } elseif(!is_null($value)) {
                $this->setSubmitValue($key, $value);
            }
        }
    }

    protected function processUploads()
    {
        $request = $this->getRequest();
        $fields = $this->getUploadableFields();
        foreach ($fields as $key => $field) {
            $file = $request->file($key);
            if (!$file) continue;

            // Delete old file
            $shouldDeleteOldFile = isset($field['delete_old_file']) AND true === $field['delete_old_file'];
            if ($this->isUpdate() AND $shouldDeleteOldFile) {
                $this->deleteUploadedFile($this->getModel(), $field);
            }

            // Upload file
            $filepath = $this->saveUploadedFile($file, $field);
            $this->setSubmitValue($key, $filepath);
        }
    }

    protected function saveUploadedFile(UploadedFile $file, $field, $i = null)
    {
        $key = $field['name'];
        $disk = $field['upload_disk'];
        $path = trim($field['upload_path'], '/');
        $filename = $field['upload_filename'];
        if ($filename instanceof Closure) {
            $resolver = $filename->bindTo($this);
            $filename = $resolver($file, $field, $i);
        }
        $filepath = $path.'/'.$filename;
        $storage = Storage::disk($disk);
        $storage->putFileAs($path, $file, $filename);
        if (isset($field['process_file']) AND $field['process_file'] instanceof Closure) {
            $field['process_file']($filepath, $storage);
        }

        $this->uploadedFiles[] = [
            'storage' => $storage,
            'filepath' => $filepath
        ];

        return $filepath;
    }

    protected function deleteUploadedFile(Model $model, $field)
    {
        $key = $field['name'];
        $disk = $field['upload_disk'];
        $path = trim($field['upload_path'], '/');
        $value = $model->{$key};
        if (!$value) return;
        if (!starts_with($value, $path)) {
            $filepath = $path.'/'.$value;
        } else {
            $filepath = $value;
        }
        $storage = Storage::disk($disk);
        if ($storage->has($filepath)) {
            $storage->delete($filepath);
        }
    }

    protected function fillModelValues()
    {
        $model = $this->getModel();
        $values = $this->getSubmitValues();
        $model->fill($values);
    }

    protected function runBeforeSave()
    {
        $callback = $this->getBeforeSave();
        if ($callback) {
            $callback = $callback->bindTo($this);
            $callback($this->getModel());
        }
    }

    protected function runBeforeSaveRelation($relationKey, Model $relation)
    {
        $callback = $this->getBeforeSaveRelation($relationKey);
        if ($callback) {
            $callback = $callback->bindTo($this);
            $callback($relation);
        }
    }

    protected function saveModel()
    {
        return $this->getModel()->save();
    }

    protected function processRelations()
    {
        foreach ($this->getRelationFields() as $key => $field) {
            $relation = $this->getModel()->{$key}();
            if ($relation instanceof HasMany) {
                $this->processHasMany($relation, $key, $field);
            } else {
                $this->processHasOne($relation, $key, $field);
            }
        }
    }

    protected function processHasMany($relation, $relationKey)
    {
        $request = $this->getRequest();
        $relationForm = $this->fields[$relationKey];
        $uploadableFields = array_filter($relationForm['fields'], function($field) {
            return $this->isUploadableField($field);
        });

        $relationModel = $relation->getRelated();
        $relationClass = get_class($relationModel);
        $relationPk = $relationModel->getKeyName();
        $relationValues = $this->getFieldHasManyValues($relationKey);
        $valueIds = array_map(function($value) use ($relationPk) {
            return $value[$relationPk];
        }, array_filter($relationValues, function($value) use ($relationPk) {
            return isset($value[$relationPk]);
        }));
        if ($this->isUpdate() AND !empty($valueIds)) {
            $queryShouldDeletes = $relation->whereNotIn($relationPk, $valueIds);
            if (count($uploadableFields)) {
                // Delete upload files
                $relations = $queryShouldDeletes->get();
                foreach ($relations as $relation) {
                    foreach ($uploadableFields as $field) {
                        $shouldDeleteOldFile = isset($field['delete_old_file']) AND true === $field['delete_old_file'];
                        if ($shouldDeleteOldFile) {
                            $this->deleteUploadedFile($relation, $field);
                        }
                    }
                }
            }

            $queryShouldDeletes->delete();
        }


        foreach ($relationValues as $i => $value) {
            $relation = isset($value[$relationPk]) ? $relationModel->find($value[$relationPk]) : new $relationClass;

            // process uploads
            foreach ($uploadableFields as $key => $field) {
                $file = $request->file($relationKey.'.'.$i.'.'.$key);
                if (!$file) continue;

                // Delete old file
                $shouldDeleteOldFile = isset($field['delete_old_file']) AND true === $field['delete_old_file'];
                if ($relation->exists AND $shouldDeleteOldFile) {
                    $this->deleteUploadedFile($relation, $field);
                }

                // Upload file
                $filepath = $this->saveUploadedFile($file, $field);
                $value[$key] = $filepath;
            }

            $relation->fill($value);
            $this->runBeforeSaveRelation($relationKey, $relation);
            if ($relation->exists) {
                $relation->save();
            } else {
                $this->getModel()->{$relationKey}()->save($relation);
            }
        }
    }

    protected function hasFieldRelation($relationKey)
    {
        return (isset($this->fields[$relationKey]) AND $this->isFieldRelation($relationKey, $this->fields[$relationKey]));
    }

    protected function checkHasFieldRelation($relationKey)
    {
        if (!$this->hasFieldRelation($relationKey)) {
            throw new Exception("This form doesn't have form relation '{$relationKey}'.");
        }
    }

    protected function validateRelationKey($relationKey)
    {
        $modelClass = get_class($this->model);
        if (!method_exists($this->model, $relationKey)) {
            throw new UnexpectedValueException("Method '{$relationKey}' doesn't exists at model '{$modelClass}'.");
        }

        $relation = $this->model->{$relationKey}();
        if (false === $relation instanceof Relation) {
            throw new UnexpectedValueException("Method '{$relationKey}' should return instance of '".(Relation::class)."'.");
        }
    }

    protected function resolveViewData(array $data = [])
    {
        return array_merge(
            static::getDefaultViewData(),
            $this->getViewData(),
            $data,
            ['form' => $this]
        );
    }

    protected function validateAndResolveFields(array $fields)
    {
        foreach ($fields as $key => $field) {
            $fields[$key] = $this->validateAndResolveField($key, $field);
        }
        return $fields;
    }

    protected function validateAndResolveField($key, array $field)
    {
        $field['name'] = $key;
        $field = array_merge([
            'exists' => true,
            'disabled' => false,
            'rules' => []
        ], $field);

        if ($this->isFieldRelation($key, $field)) {
            return $this->validateAndResolveRelationField($key, $field);
        } else {
            return $this->validateAndResolveSingleField($key, $field);
        }
    }

    protected function validateAndResolveSingleField($key, array $field)
    {
        $rulesKey = $this->isCreate() ? 'rules_create' : 'rules_update';
        $rules = isset($field[$rulesKey]) ? $field[$rulesKey]
            : (isset($field['rules']) ? $field['rules'] : []);

        $rules = $this->resolveRules($rules);
        $field['rules'] = $rules;
        if (in_array('required', $rules)) {
            $field['required'] = true;
        }

        if ($this->isUploadableField($field)) {
            if (!isset($field['upload_disk'])) {
                throw new UnexpectedValueException("Field '{$key}' must have 'upload_disk'.");
            }
            if (!isset($field['upload_path'])) {
                throw new UnexpectedValueException("Field '{$key}' must have 'upload_path'.");
            }
            if (!isset($field['upload_filename'])) {
                $field['upload_filename'] = function ($file) {
                    return uniqid().'.'.$file->extension();
                };
            }
        }

        $keyActionParams = $this->isCreate() ? 'if_create' : 'if_update';
        if (isset($field[$keyActionParams]) AND is_array($field[$keyActionParams])) {
            $field = array_merge($field, $field[$keyActionParams]);
        }

        return $field;
    }

    protected function validateAndResolveRelationField($relationKey, array $field)
    {
        $relation = $this->getModel()->{$relationKey}();
        $relatedModel = $relation->getRelated();
        $isHasMany = ($relation instanceof HasMany);

        $field['save_as'] = $relationKey;

        foreach ($field['fields'] as $key => $childField) {
            if ($this->isFieldRelation($key, $childField, $relatedModel)) {
                throw new UnexpectedValueException("Relation field must not have their own relation field.");
            }
            $field['fields'][$key] = $this->validateAndResolveField($key, $childField);
            if (!$isHasMany) {
                $field['fields'][$key]['name'] = "{$relationKey}[{$key}]";
            }
        }

        if ($this->isUpdate()) {
            if ($isHasMany) {
                $field['rows'] = $this->getRelationHasManyValues($relationKey);
            }
        }

        return $field;
    }

    protected function getRelationHasManyValues($key)
    {
        return $this->getModel()->{$key}()->get();
    }

    public function isUploadableField($field)
    {
        return (isset($field['input']) AND in_array($field['input'], ['image', 'file']));
    }

    public function isFieldRelation($key, $field, $model = null)
    {
        if (!$this->modelHasRelation($key, $model)) {
            return false;
        }

        return (isset($field['fields']) && is_array($field['fields']));
    }

    protected function modelHasRelation($key, $model = null)
    {
        $model = $model ?: $this->getModel();
        if (!method_exists($model, $key)) {
            return false;
        }

        $result = $model->{$key}();
        return ($result instanceof Relation);
    }

    protected function resolveRules($rules)
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }
        return $rules;
    }

    protected function rollback()
    {
        // Delete uploaded files
        foreach ($this->uploadedFiles as $uploadedFile) {
            $filepath = $uploadedFile['filepath'];
            $storage = $uploadedFile['storage'];
            if ($storage->has($filepath)) {
                $storage->delete($filepath);
            }
        }
    }

    protected function commit()
    {

    }

}
