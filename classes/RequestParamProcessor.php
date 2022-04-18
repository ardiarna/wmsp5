<?php

/**
 * Class RequestParamProcessor
 *
 * Converts request parameters to the expected data type.
 */
class RequestParamProcessor
{
    /**
     * Get a Local DateTime from a date representation.
     * @param string $dateValue date to be processed
     * @param string $format valid date format, as defined in {@see DateTime} class
     * @see DateTime::createFromFormat()
     * @return DateTime
     */
    public static function getLocalDate($dateValue, $format = 'Y-m-d') {
        $val = DateTime::createFromFormat($format, $dateValue);
        if (!$val) {
            throw new InvalidArgumentException("Cannot parse DateTime with format '$format' from [$dateValue]!");
        }
        return $val;
    }

    /**
     * Get a Local DateTime from a date representation, with a default fallback value.
     * @param string $dateValue date to be processed
     * @param string $format valid date format, as defined in {@see DateTime} class
     * @param string $default valid date parameter, as defined in {@see DateTime} constructor.
     * @return DateTime
     * @throws Exception if initialization with {@see \DateTime} fails
     * @see DateTime::createFromFormat()
     */
    public static function getLocalDateWithDefault($dateValue, $format = 'Y-m-d', $default = 'now') {
        $val = DateTime::createFromFormat($format, $dateValue);
        if (!$val) {
            $val = new DateTime($default);
        }
        return $val;
    }

    /**
     * Get a boolean representation from a value
     * @param mixed $boolValue value to be processed
     * @throws InvalidArgumentException if the value cannot be processed.
     * @return bool
     */
    public static function getBoolean($boolValue)
    {
        if (is_bool($boolValue)) return $boolValue;
        else if (is_int($boolValue) || is_numeric($boolValue)) return $boolValue !== 0;
        else if (is_string($boolValue)) {
            $strValue = strtolower(trim($boolValue));
            switch ($strValue) {
                case '1':
                case 'yes':
                case 'ya':
                case 'true':
                    return true;
                case '0':
                case 'no':
                case 'tidak':
                case 'false':
                    return false;
                default:
                    throw new InvalidArgumentException("Unknown boolean representation from string [$boolValue]");
            }
        } else if (is_resource($boolValue)) {
            throw new InvalidArgumentException('Unknown boolean representation from (resource) [' . get_resource_type($boolValue) . ']');
        } else if (is_object($boolValue)) {
            throw new InvalidArgumentException('Unknown boolean representation from (class) [' . get_class($boolValue) . ']');
        } else if (is_array($boolValue)) {
            throw new InvalidArgumentException('Unknown boolean representation from (array)');
        } else {
            throw new InvalidArgumentException('Unknown boolean representation from (unknown)');
        }
    }

    /**
     * Validates a subplant id, whether it is within the valid range.
     *
     * @param string $subplantId
     * @return bool
     */
    public static function validateSubplantId($subplantId) {
        $subplant = strtoupper(trim($subplantId));
        $isSubplantId = in_array($subplant, PlantIdHelper::getValidSubplants());

        // for handling plants with single plant.
        $isPlantId = !PlantIdHelper::hasSubplants() && in_array($subplantId, PlantIdHelper::$ALL_PLANT_IDS, true);
        return $isSubplantId || $isPlantId;
    }

    /**
     * Get the valid filters, based on tbl_sp_hasilbj
     * @param array $filters
     * @return array
     */
    public static function getPalletFilters(array $filters)
    {
        $validFilters = array();
        foreach ($filters as $key => $filter) {
            switch ($key) {
                case 'initial_quantity':
                case 'production_quantity':
                    $validFilters['tbl_sp_hasilbj.qty'] = $filter;
                    break;
                case 'current_quantity':
                    $validFilters['tbl_sp_hasilbj.last_qty'] = $filter;
                    break;
                case 'size':
                    $validFilters['tbl_sp_hasilbj.size'] = $filter;
                    break;
                case 'shading':
                    $validFilters['tbl_sp_hasilbj.shade'] = $filter;
                    break;
                case 'line':
                case 'production_line':
                    $validFilters['tbl_sp_hasilbj.line'] = $filter;
                    break;
            }
        }
        return $validFilters;
    }
}
