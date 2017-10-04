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

  @if(isset($groupLeft) OR isset($groupRight))
    <div class="input-group">
    {!! $groupLeft or '' !!}
  @endif

  <select
    multiple
    class="form-control"
    name="{{ $name }}"
    id="{{ $id }}"
    {{ $required? 'required' : '' }}>
    @if($empty_option)
    <option value="">{{ $empty_option }}</option>
    @endif
    @foreach($options as $option)
      @if(isset($option['options']))
        <optgroup label="{{ $option['label'] }}">
          @foreach($option['options'] as $opt)
          <option value="{{ $opt['value'] }}" {{ $value == $opt['value']? 'selected' : '' }}>{{ $opt['label'] }}</option>
          @endforeach
        </optgroup>
      @else
        <option value="{{ $option['value'] }}" {{ $value == $option['value']? 'selected' : '' }}>{{ $option['label'] }}</option>
      @endif
    @endforeach
  </select>

  @if(isset($groupLeft) OR isset($groupRight))
    {!! $groupRight or '' !!}
    </div>
  @endif

@endcomponent
