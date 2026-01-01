<?php
/*
    Catlair PHP Copyright (C) 2021 https://itserv.ru

    This program (or part of program) is free software: you can redistribute it
    and/or modify it under the terms of the GNU Aferro General Public License as
    published by the Free Software Foundation, either version 3 of the License,
    or (at your option) any later version.

    This program (or part of program) is distributed in the hope that it will be
    useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Aferro
    General Public License for more details. You should have received a copy of
    the GNU Aferror General Public License along with this program. If not, see
    <https://www.gnu.org/licenses/>.
*/


/*
    2026.01.01 - still@itserv.ru
*/


namespace catlair;



/*
    Libraries
*/
require_once( LIB . '/core/result.php' );
require_once( LIB . '/app/app.php' );



/*
    Base class - Entity Property Link
    https://zenodo.org/records/15292790
*/
class Epl extends Result
{
    /*
        Main array with entities properties and links
    */
    protected array $facts = [];



    /*
        New object
    */
    __construct
    (

    )
    {
        parent::__construct();
        $this -> app = $aApp;
    }



    /*
        Create and return new entity object
    */
    static public function constructor
    (
        App $aApp
    )
    {
        return new self( $aApp );
    }



    /**************************************************************************
        Setters and getters
    */

    /*
        Return facts
    */
    public function getFacts()
    {
        return $this -> facts;
    }
}
