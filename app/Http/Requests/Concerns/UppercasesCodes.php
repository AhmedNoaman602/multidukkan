<?php

namespace App\Http\Requests\Concerns;

// Codes (SKU, customer and supplier codes) are stored in capitals, whatever the user typed.
// Runs before validation so uniqueness is checked against the value that will be saved.
trait UppercasesCodes
{
    protected function uppercase(array $fields): void
    {
        $values = [];

        foreach ($fields as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = mb_strtoupper($this->input($field));
            }
        }

        $this->merge($values);
    }
}
