<?php


namespace App\Enums;

enum SelfIdEnums: string
{
    case AsianOrPacificIslander = 'Asian or Pacific Islander';
    case BiracialOrMultiracial = 'Biracial or Multiracial';
    case BlackOrAfricanAmerican = 'Black or African American';
    case LatinOrHispanic = 'Latin (a/o/x) or Hispanic';
    case MENA = 'MENA (Middle Eastern or North African)';
    case NativeAmericanOrAlaskaNative = 'Native American or Alaska Native';
    case WhiteOrCaucasian = 'White or Caucasian';
    case NoneOfTheProvidedOptions = 'None of the provided options';
    case ChooseNotToIdentify = 'I choose not to identify';
}