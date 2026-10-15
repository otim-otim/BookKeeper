<?php

namespace OtimOtim\BookKeeper\Enums;

enum TillType: string
{
    /** A teller's cash drawer; must have an open session to transact. */
    case Till = 'till';

    /** A branch vault or strong room. */
    case Vault = 'vault';
}
