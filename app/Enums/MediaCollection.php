<?php

namespace App\Enums;

enum MediaCollection: string
{
    use HasValues;

    case Avatar = 'avatar';
    case ListingPhoto = 'listing_photo';
    case PropertyGallery = 'property_gallery';
    case EstateGallery = 'estate_gallery';
    case Content = 'content';
    case Document = 'document';
}
