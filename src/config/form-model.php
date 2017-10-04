<?php

return [

    'default_form' => 'bs3',

    'forms' => [
        'bs3' => [
            'form' => 'form-model::bs3.form',
            'inputs' => [
                'text'  => 'form-model::bs3.fields.text',
                'date' => 'form-model::bs3.fields.date',
                'textarea' => 'form-model::bs3.fields.textarea',
                'file' => 'form-model::bs3.fields.file',
                'image' => 'form-model::bs3.fields.image',
                'number' => 'form-model::bs3.fields.number',
                'email' => 'form-model::bs3.fields.email',
                'radio' => 'form-model::bs3.fields.radio',
                'checkbox' => 'form-model::bs3.fields.checkbox',
                'select' => 'form-model::bs3.fields.select',
                'select-multiple' => 'form-model::bs3.fields.select-multiple',
            ]
        ]
    ]

];
