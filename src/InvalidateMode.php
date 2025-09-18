<?php

namespace Cache;

enum InvalidateMode
{
    case DEFAULT;
    case DELETE_SYNC;
    case DELETE_ASYNC;
    case REFRESH_SYNC;
    case REFRESH_ASYNC;
}
