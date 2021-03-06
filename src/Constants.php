<?php
declare(strict_types=1);

namespace SimpleAmqp;

class Constants
{
    const DIRECT = 'direct';
    const FANOUT = 'fanout';
    const TOPIC  = 'topic';
    const HEADER = 'header';

    const ACK = 'ack';
    const NACK = 'nack';
    const REQUEUE = 'requeue';

    const DELIVERY_MODE_NON_PERSISTENT = 1;
    const DELIVERY_MODE_PERSISTENT = 2;
}
