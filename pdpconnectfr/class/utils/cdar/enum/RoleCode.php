<?php
enum RoleCode: string
{
    case WK = 'WK'; // Dematerialization platform
    case SE = 'SE'; // Seller/Supplier
    case BY = 'BY'; // Buyer
    case CN = 'CN'; // Consignee
    case DP = 'DP'; // Delivery point

    public function getLabel(): string
    {
        return match($this) {
            self::WK => 'Dematerialization platform',
            self::SE => 'Seller/Supplier',
            self::BY => 'Buyer',
            self::CN => 'Consignee',
            self::DP => 'Delivery point',
        };
    }
}
