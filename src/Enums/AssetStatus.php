<?php

namespace OtimOtim\BookKeeper\Enums;

enum AssetStatus: string
{
    case Active = 'active';
    case FullyDepreciated = 'fully_depreciated';
    case Disposed = 'disposed';
}
