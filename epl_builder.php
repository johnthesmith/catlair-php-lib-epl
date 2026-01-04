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
require_once( LIB . '/web/builder.php' );




class EplBuilder extends Builder
{
    /* Epl object */
    private Epl $epl = null;



    /*
        Constructor builder
    */
    function __construct
    (
        /* Application object */
        App $aApp,
        /* Model EPL */
        Epl $aEpl
    )
    :self
    {
        $result = Builder::__construct( $aApp );
        $this -> epl = $aEpl;
    }



    /*
        Create new builder
    */
    public static function create
    (
        /* Application object */
        App $aApp,
        /* Model EPL */
        Epl $aEpl
    )
    :self
    {
        return $result;
    }



    /**************************************************************************
        Setters and getters
    */

    public function getEpl()
    {
        return $this -> epl;
    }

}
