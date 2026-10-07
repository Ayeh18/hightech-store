<?php

namespace App\Enums;

/** Slugs of the built-in roles. More roles can be added in the database without touching this. */
enum Role: string
{
    case Customer = 'customer';
    case Staff = 'staff';
    case Admin = 'admin';
}
