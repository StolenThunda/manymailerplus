<?php
/**
 * This source file is part of the open source project
 * ExpressionEngine (https://expressionengine.com)
 *
 * @link      https://expressionengine.com/
 * @copyright Copyright (c) 2003-2018, EllisLab, Inc. (https://ellislab.com)
 * @license   https://expressionengine.com/license Licensed under Apache License, Version 2.0
 */
namespace Manymailerplus\Model;

use ExpressionEngine\Service\Model\Model;

/**
 *
 */
class EmailCachePlus extends Model
{
    protected static $_primary_key = 'cache_id';
    protected static $_table_name = 'email_cache_plus';

    protected static $_typed_columns = array(
        'cache_date'      => 'timestamp',
        'total_sent'      => 'int',
        'recipient_array' => 'serialized',
        'attachments'     => 'serialized',
        'wordwrap'        => 'boolString',
        'csv_object'	  => 'json',
    );

    // protected static $_relationships = array(
    // 	'MemberGroups' => array(
    // 		'type' => 'hasAndBelongsToMany',
    // 		'model' => 'MemberGroup',
    // 		'pivot' => array(
    // 			'table' => 'email_cache_mg'
    // 		)
    // 	)
    // );

    protected static $_validation_rules = array(
        'cache_date'      => 'required',
        'total_sent'      => 'required',
        'from_name'       => 'required',
        'from_email'      => 'required|email',
        'recipient'       => 'required|email',
        'cc'              => 'required|email',
        'bcc'             => 'required|email',
        'recipient_array' => 'required',
        'subject'         => 'required',
        'message'         => 'required',
        'plaintext_alt'   => 'required',
        'mailtype'        => 'required',
        'text_fmt'        => 'required',
        'wordwrap'        => 'required|enum[y,n]',

    );

    protected $cache_id;
    protected $parent_id;
    protected $cache_date;
    protected $total_sent;
    protected $from_name;
    protected $from_email;
    protected $recipient;
    protected $cc;
    protected $bcc;
    protected $recipient_array;
    protected $subject;
    protected $message;
    protected $plaintext_alt;
    protected $mailtype;
    protected $text_fmt;
    protected $wordwrap;
    protected $attachments;
    protected $csv_object;
    protected $mailKey;
}

// EOF
