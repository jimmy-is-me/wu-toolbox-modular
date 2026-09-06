<?php
/**
 * TxnType class file
 *
 * @package wpbr-esun-payment
 */

namespace WPBrewer\ESun\Payment\Lib\Esunacq;

/**
 * Transaction type constants
 */
class TxnType {
    /**
     * General transaction type
     */
    const GENERAL = 'EC000001';//一般交易
    
    /**
     * Installment transaction type
     */
    const INSTALLMENT = 'EC000002';//分期交易
}
