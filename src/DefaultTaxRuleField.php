<?php

namespace Ampeco\NovaDefaultTaxRuleField;

use Exception;
use Illuminate\Support\Collection;
use Laravel\Nova\Nova;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\PerformsValidation;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Fields\SupportsDependentFields;
use Illuminate\Support\Facades\Validator;
use Laravel\Nova\Http\Requests\NovaRequest;
use ReflectionMethod;

/**
 * @method static static make(mixed $name, array|null $fields = null)
 */
class DefaultTaxRuleField extends Field
{
    use PerformsValidation, SupportsDependentFields;

    public $component = 'default-tax-rule-field';

    protected $fields = [];
    protected $rows = [];

    public function __construct($name, $fields = [])
    {
        parent::__construct($name);

        $this->fields($fields);
        $this->canAddRows(false);
        $this->canDeleteRows(false);
        $this->hideFromIndex();
        $this->addRowLabel(__('defaultTaxRuleField.addRow'));
    }

    public function fields($fields = [])
    {
        $this->fields = FieldCollection::make($fields);
    }

    public function minRows($minRows = null)
    {
        return $this->withMeta(['minRows' => $minRows]);
    }

    public function maxRows($maxRows = null)
    {
        return $this->withMeta(['maxRows' => $maxRows]);
    }

    public function canAddRows($canAddRows = true)
    {
        return $this->withMeta(['canAddRows' => $canAddRows]);
    }

    public function canDeleteRows($canDeleteRows = true)
    {
        return $this->withMeta(['canDeleteRows' => $canDeleteRows]);
    }

    public function addRowLabel($label)
    {
        return $this->withMeta(['addRowLabel' => $label]);
    }

    /*
     * Transforms repeater values to JSON instead FormData.
     * Used in cases when you have thousands of rows and you want to avoid max input vars defined in php conf.
     *
     * Currently disabled, calling this will do nothing
     */
    public function json()
    {
        return $this->withMeta(['convertFormDataToJson' => true]);
    }

    protected function isJson()
    {
        if (empty($this->meta['convertFormDataToJson'])) return false;
        return $this->meta['convertFormDataToJson'];
    }

    /**
     *
     * Validate and hydrate the given attribute on the model based on the incoming request.
     *
     * @param NovaRequest $request
     * @param $requestAttribute
     * @param $model
     * @param $attribute
     */
    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        $value = $request->input($requestAttribute) ?? null;
        $value = json_decode($value, true);

        // Do validation
        $rules = $this->getFormattedRules($request);
        Validator::make([$this->attribute => $value], $rules)->validate();

        $model->{$attribute} = $value;
    }

    public function fill(NovaRequest $request, $model)
    {
        // A fix for a bug caused by HasManyNested.
        if (str_starts_with($request->query->get('action'), 'remove-')) {
            return;
        }

        $input = $request->input($this->attribute) ?? null;
        $input = json_decode($input, true);

        if (!$input) {
            return;
        }

        // Do validation
        $rules = $this->getFormattedRules($request);
        $validationData = [];
        if ($request->input('viaRelationship')) {
            $validationData[$request->input('viaRelationship')][] = [$this->attribute => $input];
        } else {
            $validationData[$this->attribute] = $input;
        }
        Validator::make($validationData, $rules)->validate();

        foreach ($input as $rows => $rowValues) {
            foreach ($rowValues as $rowKey => $rowValue) {
                $model->{$rowKey} = $rowValue;
            }
        }
    }

    protected function getFormattedRules(NovaRequest $request)
    {
        $rules = static::formatRules(
            $request,
            collect($this->fields)->reject(function ($field) use ($request) {
                return $field->isReadonly($request);
            })->mapWithKeys(function ($field) use ($request) {
                return $field->getUpdateRules($request);
            })->mapWithKeys(function ($value, $key) use ($request) {
                if ($request->input('viaRelationship')) {
                    return ["{$request->input('viaRelationship')}.*.{$this->attribute}.*.$key" => $value];
                }

                return ["{$this->attribute}.*.$key" => $value];
            })->all()
        );

        if ($this->resource) {
            try {
                $resource = Nova::resourceForModel($this->resource);
                $formatRules = new ReflectionMethod($resource, 'formatRules');
                $formatRules->setAccessible(true);
                $newRules = $formatRules->invoke(new $resource($this->resource), $request, $rules);
                if ($newRules) $rules = $newRules;
            } catch (Exception $e) {
            }
        }

        return $rules;
    }

    /**
     * Resolve the field's and it's rows value.
     *
     * @param mixed $resource
     * @param string|null $attribute
     * @return void
     */
    public function resolve($resource, $attribute = null)
    {
        $novaRequest = app()->make(NovaRequest::class);
        $resolveForDisplay = $novaRequest->isResourceIndexRequest() || $novaRequest->isResourceDetailRequest();

        $attribute = $attribute ?? $this->attribute;
        $this->rows = $this->buildRows($resource, $attribute);

        // Resolve rows
        if ($resolveForDisplay) {
            $this->rows->each->resolveForDisplay();
        } else {
            $this->rows->each->resolve();
        }

        $this->withMeta([
            'rows' => $this->rows,
            'fields' => $this->fields->resolve(null) // Empty fields
        ]);

        return parent::resolve($resource, $attribute);
    }

    /**
     * Define the field's actual rows (as "base models") based
     * on the field's current model & attribute
     *
     * @param mixed $resource
     * @param string $attribute
     * @return Collection
     */
    public function buildRows($resource, $attribute)
    {
        $result = [];
        foreach ($this->fields as $field) {
            $result[0][$field->attribute] = $resource->{$field->attribute};
        }

        return collect($result)->map(function ($rowValue) {
            return Row::make($this->fields, $rowValue);
        })->filter()->values();
    }
}
