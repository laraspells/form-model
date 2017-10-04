<?php

namespace LaraSpells\FormModel;

use Closure;
use DB;
use Exception;
use Storage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
    protected $rules = [];
    protected $rulesCreate = [];
    protected $rulesUpdate = [];
    protected $scripts = [];
    protected $styles = [];
    protected $inputViews = [];

    protected $childs = [];

    protected $formDataResolver; 
    protected $requestDataResolver; 
    protected $beforeSave;
    protected $beforeSaveChild;


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
        $this->rules = array_merge($this->rules, $this->isCreate() ? $rulesCreate : $rulesUpdate);

        return $this;
    }

    public function withInputViews(array $inputViews)
    {
        $this->inputViews = array_merge($this->inputViews, $inputViews);
        return $this;
    }

    public function withMany($relationKey, $label, array $fields)
    {
        $this->validateRelationKey($relationKey);
        $this->childs[$relationKey] = [
            'label' => $label,
            'fields' => $this->validateAndResolveFields($fields, $relationKey.'.*.'),
            'items' => $this->isCreate() ? [] : $this->getModel()->{$relationKey}()->get()
        ];
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

    public function getChilds()
    {
        return $this->childs;
    }

    public function getFormChild($relationKey)
    {
        return isset($this->childs[$relationKey]) ? $this->childs[$relationKey] : null;
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

    public function getBeforeSaveChild($key)
    {
        return isset($this->beforeSaveChild[$key]) ? $this->beforeSaveChild[$key] : null;
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

    public function hasUploadableField($includeChilds = true)
    {
        if (count($this->getUploadableFields())) {
            return true;
        }
        foreach ($this->getChilds() as $child) {
            foreach ($child['fields'] as $field) {
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
            return isset($field['input']);
        });
    }

    public function getRenderValue($key)
    {
        $field = $this->getField($key);
        $value = ($field AND isset($field['default'])) ? $field['default'] : null;
        if ($this->isUpdate()) {
            $value = $this->getModel()->{$key};
            if ($field AND isset($field[static::KEY_RENDER_VALUE]) AND $field[static::KEY_RENDER_VALUE] instanceof Closure) {
                $resolver = $field[static::KEY_RENDER_VALUE]->bindTo($this);
                $value = $resolver($value);
            }
        }

        return session($key) ?: old($key) ?: $value;
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

    public function getChildSubmitValues($relationKey)
    {
        $this->checkHasChild($relationKey);

        $request = $this->getRequest();
        $values = $request->get($relationKey);
        if (!is_array($values)) {
            return [];
        }

        // resolve values
        $formChild = $this->getFormChild($relationKey);
        $childFields = $formChild['fields'];
        foreach ($values as $i => $value) {
            foreach ($value as $k => $v) {
                $resolver = (
                    isset($childFields[$k]) 
                    AND isset($childFields[$k][static::KEY_SUBMIT_VALUE]) 
                    AND $childFields[$k][static::KEY_SUBMIT_VALUE] instanceof Closure
                )? $childFields[$k][static::KEY_SUBMIT_VALUE] : null;

                if ($resolver) {
                    $resolver = $resolver->bindTo($this);
                    $values[$i][$k] = $resolver($v);
                }
            }
        }
        return $values;
    }

    public function beforeSaveChild($relationKey, Closure $callback)
    {
        $this->checkHasChild($relationKey);
        $this->beforeSaveChild[$relationKey] = $callback;
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
        $this->processChilds();
    }

    protected function processUpdate()
    {
        $this->validateForm();
        $this->resolveSubmitValues();
        $this->processUploads();
        $this->fillModelValues();
        $this->runBeforeSave();
        $saved = $this->saveModel();
        $this->processChilds();
    }

    protected function validateForm()
    {
        $request = $this->getRequest();
        $rules = $this->isUpdate() ? $this->getRulesUpdate() : $this->getRulesCreate();
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

    protected function runBeforeSaveChild($relationKey, Model $child)
    {
        $callback = $this->getBeforeSaveChild($relationKey);
        if ($callback) {
            $callback = $callback->bindTo($this);
            $callback($child);
        }
    }

    protected function saveModel()
    {
        return $this->getModel()->save();
    }

    protected function processChilds()
    {
        foreach ($this->getChilds() as $key => $child) {
            $this->processChild($key);
        }
    }

    protected function processChild($relationKey)
    {
        $request = $this->getRequest();
        $childForm = $this->childs[$relationKey];
        $uploadableFields = array_filter($childForm['fields'], function($field) {
            return $this->isUploadableField($field);
        });

        $relation = $this->getModel()->{$relationKey}();
        $childModel = $relation->getRelated();
        $childClass = get_class($childModel);
        $childPk = $childModel->getKeyName();
        $childValues = $this->getChildSubmitValues($relationKey);
        $valueIds = array_map(function($value) use ($childPk) {
            return $value[$childPk];
        }, array_filter($childValues, function($value) use ($childPk) {
            return isset($value[$childPk]);
        }));
        if ($this->isUpdate() AND !empty($valueIds)) {
            $queryShouldDeletes = $relation->whereNotIn($childPk, $valueIds);
            if (count($uploadableFields)) {
                // Delete upload files
                $childs = $queryShouldDeletes->get();
                foreach ($childs as $child) {
                    foreach ($uploadableFields as $field) {
                        $shouldDeleteOldFile = isset($field['delete_old_file']) AND true === $field['delete_old_file'];
                        if ($shouldDeleteOldFile) {
                            $this->deleteUploadedFile($child, $field);
                        }
                    }
                }
            }

            $queryShouldDeletes->delete();
        }


        foreach ($childValues as $i => $value) {
            $child = isset($value[$childPk]) ? $childModel->find($value[$childPk]) : new $childClass;

            // process uploads
            foreach ($uploadableFields as $key => $field) {
                $file = $request->file($relationKey.'.'.$i.'.'.$key);
                if (!$file) continue;

                // Delete old file
                $shouldDeleteOldFile = isset($field['delete_old_file']) AND true === $field['delete_old_file'];
                if ($child->exists AND $shouldDeleteOldFile) {
                    $this->deleteUploadedFile($child, $field);
                }
                
                // Upload file
                $filepath = $this->saveUploadedFile($file, $field);
                $value[$key] = $filepath;
            }

            $child->fill($value);
            $this->runBeforeSaveChild($relationKey, $child);
            if ($child->exists) {
                $child->save();
            } else {
                $this->getModel()->{$relationKey}()->save($child);
            }
        }
    }

    protected function checkHasChild($relationKey)
    {
        if (!isset($this->childs[$relationKey])) {
            throw new Exception("Form ini tidak memiliki child form '{$relationKey}'.");
        }
    }

    protected function validateRelationKey($relationKey)
    {
        $modelClass = get_class($this->model);
        if (!method_exists($this->model, $relationKey)) {
            throw new UnexpectedValueException("Method '{$relationKey}' tidak terdaftar pada model '{$modelClass}'.");
        }

        $relation = $this->model->{$relationKey}();
        if (false === $relation instanceof HasMany) {
            throw new UnexpectedValueException("Relasi '{$relationKey}' pada '{$modelClass}' tidak bersifat hasMany.");
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

    protected function validateAndResolveFields(array $fields, $keyPrefix = null)
    {
        $rulesKey = $this->isCreate() ? 'rules_create' : 'rules_update';
        foreach ($fields as $key => $field) {
            $fields[$key]['name'] = $key;
            $rules = isset($field[$rulesKey]) ? $field[$rulesKey] 
                : (isset($field['rules']) ? $field['rules'] : []);

            $rules = $this->resolveRules($rules);
            $this->mergeRules($keyPrefix.$key, $rules);
            $fields[$key]['rules'] = $rules;
            if (in_array('required', $rules)) {
                $fields[$key]['required'] = true;
            }

            if ($this->isUploadableField($field)) {
                if (!isset($field['upload_disk'])) {
                    throw new UnexpectedValueException("Harap masukkan 'upload_disk' pada field '{$key}'.");
                }
                if (!isset($field['upload_path'])) {
                    throw new UnexpectedValueException("Harap masukkan 'upload_path' pada field '{$key}'.");
                }
                if (!isset($field['upload_filename'])) {
                    $fields[$key]['upload_filename'] = function ($file) {
                        return uniqid().'.'.$file->extension();
                    };
                }
            }
        }

        return $fields;
    }

    protected function mergeRules($key, $rules)
    {
        if (isset($this->rules[$key])) {
            $this->rules[$key] = array_unique(array_merge($this->rules[$key], $rules));
        } else {
            $this->rules[$key] = $rules;
        }
    }

    protected function isUploadableField($field)
    {
        return (isset($field['input']) AND in_array($field['input'], ['image', 'file']));
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
