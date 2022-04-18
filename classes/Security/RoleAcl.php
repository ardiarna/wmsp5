<?php

namespace Security;

use UserRole;

/**
 * Class RoleAcl
 *
 * Defines related {@see UserRole} that can access a particular feature (service/screen)
 * @package Security
 */
class RoleAcl
{
    /**
     * @return array
     */
    public static function masterArea()
    {
        return array_merge(self::masterAreaModification(), array(
            UserRole::ROLE_ACCOUNTING_MANAGER, UserRole::ROLE_ACCOUNTING_STAFF,
            UserRole::ROLE_AUDIT_MANAGER, UserRole::ROLE_AUDIT_STAFF,
            UserRole::ROLE_WAREHOUSE_ADMIN, UserRole::ROLE_WAREHOUSE_STOCKIST, UserRole::ROLE_WAREHOUSE_CHECKER,
            UserRole::ROLE_WMM_KABAG
        ));
    }

    /**
     * @return array
     */
    public static function masterAreaModification()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_WAREHOUSE_MANAGER, UserRole::ROLE_WAREHOUSE_KABAG, UserRole::ROLE_WAREHOUSE_SUPERVISOR
        );
    }

    /**
     * @return array
     */
    public static function mutationReport()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_ACCOUNTING_MANAGER, UserRole::ROLE_ACCOUNTING_STAFF,
            UserRole::ROLE_AUDIT_MANAGER, UserRole::ROLE_AUDIT_STAFF,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_QA_MANAGER, UserRole::ROLE_QA_KABAG,
            UserRole::ROLE_WAREHOUSE_ADMIN, UserRole::ROLE_WAREHOUSE_STOCKIST, UserRole::ROLE_WAREHOUSE_SUPERVISOR,
            UserRole::ROLE_WAREHOUSE_KABAG, UserRole::ROLE_WAREHOUSE_MANAGER
        );
    }

    /**
     * @return array
     */
    public static function palletsWithoutLocation()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_ACCOUNTING_MANAGER, UserRole::ROLE_ACCOUNTING_STAFF,
            UserRole::ROLE_AUDIT_MANAGER, UserRole::ROLE_AUDIT_STAFF,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_QA_MANAGER, UserRole::ROLE_QA_KABAG,
            UserRole::ROLE_WAREHOUSE_ADMIN, UserRole::ROLE_WAREHOUSE_STOCKIST, UserRole::ROLE_WAREHOUSE_SUPERVISOR,
            UserRole::ROLE_WAREHOUSE_KABAG, UserRole::ROLE_WAREHOUSE_MANAGER
        );
    }

    /**
     * @return array
     */
    public static function downgradePallets()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_ACCOUNTING_MANAGER, UserRole::ROLE_ACCOUNTING_STAFF,
            UserRole::ROLE_AUDIT_MANAGER, UserRole::ROLE_AUDIT_STAFF,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_QA_MANAGER, UserRole::ROLE_QA_KABAG, UserRole::ROLE_QA_SUPEVISOR, UserRole::ROLE_QA_STAFF,
            UserRole::ROLE_WAREHOUSE_ADMIN, UserRole::ROLE_WAREHOUSE_STOCKIST, UserRole::ROLE_WAREHOUSE_SUPERVISOR,
            UserRole::ROLE_WAREHOUSE_KABAG, UserRole::ROLE_WAREHOUSE_MANAGER
        );
    }

    /**
     * TODO: refactor and simplify
     * @return array
     */
    public static function downgradePalletsModification()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_QA_MANAGER, UserRole::ROLE_QA_KABAG, UserRole::ROLE_QA_SUPEVISOR, UserRole::ROLE_QA_STAFF
        );
    }

    /**
     * TODO: refactor and simplify
     * @return array
     */
    public static function downgradePalletsReasonModification()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_QA_MANAGER, UserRole::ROLE_QA_KABAG, UserRole::ROLE_QA_SUPEVISOR
        );
    }

    /**
     * TODO: refactor and simplify
     * @return array
     */
    public static function downgradePalletsApproval()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_PLANT_MANAGER,
            UserRole::ROLE_QA_MANAGER, UserRole::ROLE_QA_KABAG
        );
    }

    /**
     * TODO: refactor and simplify
     * @return array
     */
    public static function blockQuantity()
    {
        return array(
            UserRole::ROLE_SUPERUSER,
            UserRole::ROLE_WAREHOUSE_MANAGER, UserRole::ROLE_WAREHOUSE_KABAG, UserRole::ROLE_WAREHOUSE_ADMIN,
            UserRole::ROLE_WAREHOUSE_COORDINATOR, UserRole::ROLE_WAREHOUSE_SUPERVISOR, UserRole::ROLE_WAREHOUSE_CHECKER,
            UserRole::ROLE_WAREHOUSE_STOCKIST, UserRole::ROLE_WAREHOUSE_HANDOVER,
            UserRole::ROLE_MARKETING_MANAGER, UserRole::ROLE_MARKETING_STAFF
        );
    }
}
