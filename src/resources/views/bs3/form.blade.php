<form class="form-horizontal"
  id="form-create"
  method="POST"
  action="{{ $form->getAction() }}"
  @if($form->hasUploadableField(true))
  enctype="multipart/form-data"
  @endif
  >

  {!! csrf_field() !!}

  @foreach($form->getFields() as $key => $field)
    @include($form->getInputView($field['input'], 'form-model::bs3.fields.'.$field['input']), array_merge($field, [
      'value' => $form->getRenderValue($key)
    ]))
  @endforeach

  @foreach($form->getChilds() as $key => $formChild)
    @component('form-model::bs3.form-child', [
      'name' => $key,
      'label' => $formChild['label'],
      'fields' => $formChild['fields'],
      'table' => array_values(array_map(function($field) {
        return [
          'key' => $field['name'],
          'label' => $field['label']
        ];
      }, $formChild['fields'])),
      'rows' => $formChild['items'],
    ])
      @foreach($formChild['fields'] as $field)
        @include('form-model::bs3.fields.'.$field['input'], array_merge($field, [
          'value' => $form->getRenderValue($key)
        ]))
      @endforeach
    @endcomponent
  @endforeach

  @component('form-model::bs3.fields.wrapper', ['name' => '', 'label' => false])
    @if($form->isCreate())
      <button class='btn btn-success'><i class="fa fa-plus"></i> Create</button>
    @else
      <button class='btn btn-primary'><i class="fa fa-save"></i> Save</button>
    @endif
  @endcomponent
</form>
