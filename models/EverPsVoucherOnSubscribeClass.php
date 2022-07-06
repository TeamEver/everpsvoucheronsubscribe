<?php
/**
 * Project : everpsvoucheronsubscribe
 * @author Team Ever
 * @copyright Team Ever
 * @license   Tous droits réservés / Le droit d'auteur s'applique (All rights reserved / French copyright law applies)
 * @link https://www.team-ever.com
 */

class EverPsVoucherOnSubscribeClass extends ObjectModel
{
    public $id_everpsvoucheronsubscribe;
    public $id_customer;
    public $email;
    public $voucher_code;

    public static $definition = array(
        'table' => 'everpsvoucheronsubscribe',
        'primary' => 'id_everpsvoucheronsubscribe',
        'multilang' => false,
        'fields' => array(
            'id_customer' => array(
                'type' => self::TYPE_INT,
                'lang' => false,
                'validate' => 'isunsignedInt',
                'required' => true
            ),
            'email' => array(
                'type' => self::TYPE_STRING,
                'lang' => false,
                'validate' => 'isEmail'
            ),
            'voucher_code' => array(
                'type' => self::TYPE_STRING,
                'lang' => false,
                'validate' => 'isString'
            ),
        )
    );

    public static function getByCustomer($email)
    {
        $sql = new DbQuery();
        $sql->select('id_everpsvoucheronsubscribe');
        $sql->from('everpsvoucheronsubscribe');
        $sql->where(
            'email = "'.(int)$email.'"'
        );
        return Db::getInstance()->getValue($sql);
    }
}
