<?php

namespace Cache;

enum CacheReadState
{
    case HIT;
    case STALE;
    case MISS;
}
