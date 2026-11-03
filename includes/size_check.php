<?php

function validate_size($label, $price) {
    $errors = array();

    if ($label === "") {
        $errors[] = "Size name is required.";
    } else {
        if (strlen($label) > 30) {
            $errors[] = "Size name must be 30 characters or less.";
        }
        if (!preg_match("/^[a-zA-Z0-9 ]+$/", $label)) {
            $errors[] = "Size name can only have letters, numbers, and spaces.";
        }
    }

    if ($price === "") {
        $errors[] = "Price is required.";
    } else if (!preg_match("/^\d+(\.\d{1,2})?$/", $price)) {
        $errors[] = "Price must be a number with up to 2 decimals (example: 70 or 70.50).";
    } else if ($price <= 0) {
        $errors[] = "Price must be more than 0.";
    } else if ($price > 9999.99) {
        $errors[] = "Price must not be more than 9999.99.";
    }

    return $errors;
}