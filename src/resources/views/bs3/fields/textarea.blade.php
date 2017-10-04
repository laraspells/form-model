@php
$id = "input-{$name}";
$label = isset($label)? $label : ucwords(snake_case(camel_case($name), ' '));
$required = isset($required)? (bool) $required : false;
@endphp

@component('form-model::bs3.fields.wrapper', [
  'name' => $name, 
  'label' => $label,
  'required' => $required
])
<textarea class="form-control" name="{{ $name }}" id="{{ $id }}" {{ $required? 'required' : '' }}>{{ $value }}</textarea>
@endcomponent
