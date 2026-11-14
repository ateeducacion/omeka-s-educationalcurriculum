<?php
declare(strict_types=1);

namespace Omeka\Form;

class ConfirmForm
{
    public $attributes = [];
    public $buttonLabel;
    public $data;
    public $valid = true;

    public function setAttribute($name, $value)
    {
        $this->attributes[$name] = $value;
        return $this;
    }

    public function setButtonLabel($label)
    {
        $this->buttonLabel = $label;
    }

    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }

    public function isValid()
    {
        return $this->valid;
    }
}
