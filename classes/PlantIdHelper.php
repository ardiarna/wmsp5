<?php /** @noinspection PhpUnusedPrivateFieldInspection */

use Utils\Env;

/**
 * Class PlantIdHelper
 *
 * Helper class to convert between old plant id to new plant formatting
 */
class PlantIdHelper
{
    private static $SUBPLANTS = array('A', 'B', 'C');

    public static $SUBPLANTS_LOC_1 = array('1');
    public static $SUBPLANTS_LOC_2 = array('A', 'B', 'C');
    public static $SUBPLANTS_LOC_3 = array('03', '04');
    public static $SUBPLANTS_LOC_4 = array('04');
    public static $SUBPLANTS_LOC_5 = array('05');

    public static $SUBPLANTS_PROD_1 = array('1');
    public static $SUBPLANTS_PROD_2 = array('2A', '2B', '2C');
    public static $SUBPLANTS_PROD_3 = array('3A', '3B', '3C');
    public static $SUBPLANTS_PROD_4 = array('4', '4A', '4B');
    public static $SUBPLANTS_PROD_5 = array('5', '5A', '5B');

    public static $ALL_PLANT_IDS = array('1', '2', '3', '4', '5');

    /**
     * Lists all aggregate queries for use.
     * @return array
     */
    public static function getAggregateQueryTypes()
    {
        return array('all', 'local', 'other');
    }

    public static function getCurrentPlant()
    {
        return intval(Env::get('PLANT_ID', 0));
    }

    public static function locationRegex()
    {
        if (self::usesLocationCell()) {
            return '/^([0-9A-Z])([0-9A-Z]{2})(\d{2})(\d{3})$/';
        } else {
            return '/^([0-9A-Z]{2})([0-9A-Z]{3})(\d{3})$/';
        }
    }

    public static function palletIdRegex()
    {
        return '/^(PLT|PLM|PLR)\/(\d[A-C]?)\/(0[1-9]|1[0-2])\/(\d{2})\/(\d{5})$/';
    }

    /**
     * Get all valid subplants for the current plant.
     * @return array
     */
    public static function getValidSubplants()
    {
        if (!in_array(self::getCurrentPlant(), self::$ALL_PLANT_IDS)) {
            throw new RuntimeException('Unknown plant [' . self::getCurrentPlant() . ']');
        }

        // get valid subplants from synced subplants, if exists.
        $syncPlants = Env::has('SYNC_PLANTS') ? explode(',', Env::get('SYNC_PLANTS')) : array();
        $syncSubplants = empty($syncPlants) ? array() : array_reduce($syncPlants, function ($carry, $item) {
            return array_merge($carry, PlantIdHelper::${'SUBPLANTS_PROD_' . $item}, PlantIdHelper::${'SUBPLANTS_LOC_' . $item});
        }, array());

        return array_merge($syncSubplants, self::${'SUBPLANTS_PROD_' . self::getCurrentPlant()}, self::${'SUBPLANTS_LOC_' . self::getCurrentPlant()});
    }

    /**
     * Check if the current plant has subplants.
     * @return bool
     */
    public static function hasSubplants()
    {
        switch (self::getCurrentPlant()) {
            case 1:
            case 5:
                return false;
            case 2:
            case 3:
			case 4:
                return true;
            default:
                throw new RuntimeException('Unknown plant [' . self::getCurrentPlant() . ']');
        }
    }

    public static function getWarehouseIds($plantId) {
        if (!property_exists('PlantIdHelper', "SUBPLANTS_LOC_$plantId")) {
            throw new RuntimeException("PlantIdHelper::SUBPLANTS_LOC_$plantId is not defined!");
        }
        return PlantIdHelper::${'SUBPLANTS_LOC_' . $plantId};
    }

    /**
     * Designates whether the plant uses location-per-cell or location-per-line mode.
     *
     * @return boolean true if the plant uses location-per-cell mode.
     */
    public static function usesLocationCell()
    {
        $plantsWithCellMode = array(1, 2);
        return in_array(self::getCurrentPlant(), $plantsWithCellMode);
    }

    /**
     * Converts a string to the proper subplant ID
     * Handles old formatting in the DB, e.g. 'A', 'B', or '0'
     *
     * NOTE: remove this function once the database has been streamlined.
     * @param string $oldPlantId
     * @return string new subplant id
     */
    public static function toSubplantId($oldPlantId)
    {
        $oldPlantId = strtoupper($oldPlantId);

        if (in_array($oldPlantId, self::$SUBPLANTS, true)) {
            return self::getCurrentPlant() . $oldPlantId;
        } else if ($oldPlantId === '0') {
            return self::getCurrentPlant() . 'A'; // for handling 4A GBJ
        } else if ($oldPlantId == self::getCurrentPlant() && self::getCurrentPlant() === 4) {
            return self::getCurrentPlant() . 'A';
        } else if (strlen($oldPlantId) === 2 && self::getCurrentPlant() === 2) {
            return $oldPlantId[1];
        } else {
            return $oldPlantId;
        }
    }

    /**
     * Get the plant ID from a subplant ID
     *
     * @param string $subplantId
     * @return string
     */
    public static function getPlantIdFromSubplantId($subplantId)
    {
        $len = strlen($subplantId);
        $subplantIdValid = $len > 0 && $len <= 2;
        if ($subplantIdValid) {
            $subplantIdValid = is_numeric($subplantId[0]);
        }
        if (!$subplantIdValid) {
            throw new InvalidArgumentException("Unknown subplant id [$subplantId]");
        }

        return $subplantId[0];
    }
}
