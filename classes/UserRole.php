<?php

class UserRole
{
    const ROLE_SUPERUSER = 'SU';

    const ROLE_WAREHOUSE_MANAGER = 'LM';
    const ROLE_WAREHOUSE_KABAG = 'LK';
    const ROLE_WAREHOUSE_ADMIN = 'LA';
    const ROLE_WAREHOUSE_COORDINATOR = 'LC';
    const ROLE_WAREHOUSE_SUPERVISOR = 'KS';
    const ROLE_WAREHOUSE_CHECKER = 'CK';
    const ROLE_WAREHOUSE_STOCKIST = 'SK';

    // TODO check
    const ROLE_WAREHOUSE_HANDOVER = 'LH';

    const ROLE_MARKETING_MANAGER = 'MM';
    const ROLE_MARKETING_STAFF = 'MS';

    const ROLE_ACCOUNTING_MANAGER = 'AM';
    const ROLE_ACCOUNTING_STAFF = 'AS';

    const ROLE_AUDIT_MANAGER = 'CM';
    const ROLE_AUDIT_STAFF = 'CS';

    const ROLE_QA_STAFF = 'QO';
    const ROLE_QA_SUPEVISOR = 'QS';
    const ROLE_QA_KABAG = 'QK';
    const ROLE_QA_MANAGER = 'QM';

    const ROLE_WMM_KABAG = 'WK';
    const ROLE_PRODUCTION_KABAG = 'PK';

    const ROLE_PLANT_MANAGER = 'PM';

    /**
     * Checks if the current authenticated user is a member of any of the roles.
     *
     * @param array $roles roles to check against
     * @return bool
     */
    public static function hasAnyRole(array $roles) {
        $user = SessionUtils::getUser();
        if ($user === null) {
            throw new RuntimeException('session not started or you are not authenticated!');
        }

        $hasRole = false;
        foreach ($roles as $role) {
            $hasRole = in_array($role, $user->roles);
            if ($hasRole) break;
        }
        return $hasRole;
    }

    /**
     * Checks if the user is a superuser.
     * @return bool
     */
    public static function isSuperuser()
    {
        return self::hasAnyRole(array(self::ROLE_SUPERUSER)) || isset($_SESSION['superuser']);
    }

    public static function getFullClassName()
    {
        return get_called_class();
    }
}
