<?php

/**
 * Class QueryResultConverter
 *
 * Helper methods to transform data from the DB to a certain format.
 */
class QueryResultConverter
{
    /**
     * Transforms the motif parameter from the DB to Motif object-style, that contains the following elements:
     * - (string) id: ID of the motif.
     * - (string) name: name of the motif.
     * - (string) dimension: dimension of the motif.
     *
     * Useful for handling APIs that require strict JSON-to-Object mapping
     *
     * @param array $item
     * @param string $prefix
     * @param string $resultKey
     * @return array
     */
    public static function transformMotif(array $item, $prefix = 'motif_', $resultKey = 'motif')
    {
        $keysToCheck = array(
            'id' => "${prefix}id",
            'name' => "${prefix}name",
            'dimension' => "${prefix}dimension")
        ;
        $missingMotifKeys = array();
        foreach ($keysToCheck as $key) {
            if (!array_key_exists($key, $item)) {
                $missingMotifKeys[] = $key;
            }
        }

        if (!empty($missingMotifKeys)) {
            $missingMotifKeys_str = implode(',', array_values($missingMotifKeys));
            throw new InvalidArgumentException("Missing keys for motif transformation: $missingMotifKeys_str");
        }

        // do the transformation

        $item[$resultKey] = array();
        foreach ($keysToCheck as $compositeKey => $flatKey) {
            $item[$resultKey][$compositeKey] = $item[$flatKey];
            unset($item[$flatKey]);
        }
        return $item;
    }

    /**
     * Transforms the pallet transaction parameter (sent-to-warehouse, marked-for-handover) from the DB,
     * that contains the following elements:
     *  - (string) no confirmation number of the transaction
     *  - (string) date timestamp of the transaction
     *  - (string) userid user that committed the transaction.
     *
     * Useful for handling APIs that require strict JSON-to-Object mapping
     *
     * @param array $item
     * @param string $prefix
     * @param string $resultKey
     * @return array
     */
    public static function transformPalletTransaction(array $item, $prefix, $resultKey) {
        $keysToCheck = array(
            'no' => "${prefix}no",
            'date' => "${prefix}date",
            'userid' => "${prefix}userid")
        ;

        // validate
        $missingMotifKeys = array();
        foreach ($keysToCheck as $key) {
            if (!array_key_exists($key, $item)) {
                $missingMotifKeys[] = $key;
            }
        }
        if (!empty($missingMotifKeys)) {
            $missingMotifKeys_str = implode(',', array_values($missingMotifKeys));
            throw new InvalidArgumentException("Missing keys for palletTransaction transformation: $missingMotifKeys_str");
        }

        // do the transformation
        // check for null value(s)
        if (is_null($item[$keysToCheck['no']])) {
            $item[$resultKey] = null;
            foreach ($keysToCheck as $compositeKey => $flatKey) {
                unset($item[$flatKey]);
            }
        } else {
            $item[$resultKey] = array();
            foreach ($keysToCheck as $compositeKey => $flatKey) {
                $item[$resultKey][$compositeKey] = $item[$flatKey];
                unset($item[$flatKey]);
            }
        }
        return $item;
    }

    /**
     * Transforms all elements of $item, designated by $params, to int.
     *
     * Note that no mutation is done on the item.
     *
     * @param array $item
     * @param array $params
     * @return array new resulting item, with converted values.
     * @throws InvalidArgumentException if any of the supplied params is missing.
     */
    public static function toInt(array $item, array $params) {
        if (!empty($params)) {
            $missingParams = array();

            foreach ($params as $param) {
                if (isset($item[$param])) {
                    $item[$param] = intval($item[$param]);
                } else {
                    $missingParams[] = $param;
                }
            }
            if (!empty($missingParams)) {
                $missingParams_str = implode(',', $missingParams);
                throw new InvalidArgumentException("toInt(): Missing parameters [$missingParams_str]");
            }
        }
        return $item;
    }

    /**
     * Transforms default boolean response from PostgreSQL to PHP bool.
     * @param string|mixed $boolParam value to check against
     * @return bool
     * @throws InvalidArgumentException if the parameter is not recognized.
     */
    public static function toBool($boolParam) {
        if ($boolParam === PostgresqlDatabase::PGSQL_TRUE) {
            return true;
        } else if ($boolParam === PostgresqlDatabase::PGSQL_FALSE) {
            return false;
        } else {
            throw new InvalidArgumentException("Unknown boolParam [$boolParam]");
        }
    }
}
