@php
$id = "input-{$name}";
$label = isset($label)? $label : ucwords(snake_case(camel_case($name), ' '));
$required = isset($required)? (bool) $required : false;
$empty_option = isset($empty_option)? $empty_option : 'Pick '.$label;
$value = isset($value)? $value : '';
@endphp

@component('form-model::bs3.fields.wrapper', [
  'name' => $name, 
  'label' => $label,
  'required' => $required
])

@foreach($options as $option)
  <div class="input-radio">
    <input id="cb-{{ $id }}-{{ $option['value'] }}" name="{{ $name }}" type='radio' value="{{ $option['value'] }}" {{ $value == $option['value']? 'checked' : '' }}>
    <span>{{ $option['label'] }}</span>
  </div>
@endforeach

@endcomponent
