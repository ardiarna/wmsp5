<?php

namespace Model;

class PalletDowngrade
{
    const STATUS_APPROVED = 'A';
    const STATUS_REJECTED = 'R';
    const STATUS_OPEN = 'O';
    const STATUS_CANCELLED = 'C';

    const TYPE_EXP_TO_ECO = '1';
    const TYPE_EXP_TO_KW4 = '2';
    const TYPE_ECO_TO_KW4 = '3';

    public static function availableStatus()
    {
        return array(
            self::STATUS_OPEN => 'Dalam Proses',
            self::STATUS_REJECTED => 'Ditolak',
            self::STATUS_APPROVED => 'Disetujui',
            self::STATUS_CANCELLED => 'Dibatalkan'
        );
    }

    public static function availableTypes()
    {
        return array(
            self::TYPE_EXP_TO_ECO => array(
                'label' => 'EXP ke ECO',
                'quality_src' => 'EXPORT',
                'quality_target' => 'EKONOMI'
            ),
            self::TYPE_EXP_TO_KW4 => array(
                'label' => 'EXP ke KW4',
                'quality_src' => 'EXPORT',
                'quality_target' => 'KW4'
            ),
            self::TYPE_ECO_TO_KW4 => array(
                'label' => 'ECO ke KW4',
                'quality_src' => 'EKONOMI',
                'quality_target' => 'KW4'
            )
        );
    }
}
