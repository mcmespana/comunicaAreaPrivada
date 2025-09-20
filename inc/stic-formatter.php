<?php
/**
 * Return a $value with a certain $format
 */
function formatValue($value, $formatIn)
{
    if (is_array($formatIn)) {
        $format = $formatIn[0];
    }
    else {
        $format = $formatIn;
    }
    switch ($format) {
        case 'currency':
            if ($value) {
                $fmt = new NumberFormatter('es', NumberFormatter::CURRENCY);
                $fmt->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, 2);
                return $fmt->formatCurrency((float)$value, 'EUR');
            } else {
                return null;
            }
            break;
        case 'upper':
            return strtoupper($value);
            break;
        case 'date';
            if (!empty($value))
				return date('d-m-Y', strtotime($value));
			else
				return '';
            break;
        case 'datetime':
            if (!empty($value)) {
				return get_date_from_gmt($value, 'd-m-Y H:i:s');
            }
			else
				return '';
            break;
        case 'translate';
            return __($value,'sticpa');
            break;
        case 'callback':
            $function = $formatIn[1];
            return $function($value);
            break;
        default:
            return $value;
            break;
    }

}
