<form class="form-horizontal"
  id="form-create"
  method="POST"
  action="{{ $form->getAction() }}"
  @if($form->hasUploadableField(true))
  enctype="multipart/form-data"
  @endif
  >

  {!! csrf_field() !!}

  @foreach($form->getExistsFields() as $key => $field)
    @if($form->isFieldRelation($key, $field))
      @component(isset($field['view_form']) ? $field['view_form'] : 'form-model::bs3.form-child', array_merge($field, [
        'name' => $key,
        'table' => array_values(array_map(function($field) {
          return [
            'key' => $field['name'],
            'label' => $field['label']
          ];
        }, $field['fields']))
      ]))
        @foreach($field['fields'] as $childField)
          @include($form->getInputView($childField['input'], 'form-model::bs3.fields.'.$childField['input']), array_merge($childField, [
            'value' => $form->getRenderValue($key)
          ]))
        @endforeach
      @endcomponent
    @else
      @include($form->getInputView($field['input'], 'form-model::bs3.fields.'.$field['input']), array_merge($field, [
        'value' => $form->getRenderValue($key)
      ]))
    @endif
  @endforeach

  @component('form-model::bs3.fields.wrapper', ['name' => '', 'label' => false])
    @if($form->isCreate())
      <button class='btn btn-success'><i class="fa fa-plus"></i> Create</button>
    @else
      {!! method_field('PUT') !!}
      <button class='btn btn-primary'><i class="fa fa-save"></i> Save</button>
    @endif
  @endcomponent
</form>

@foreach($form->getScripts() as $script)
@js($script)
@endforeach
