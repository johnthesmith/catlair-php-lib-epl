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



class EplBuilder extends Result
{
    /* Epl object */
    private ?Epl $epl = null;

    /* Result path */
    private ?string $destination = null;

    /* Result metamodel path */
    private string $metamodel = 'file.yaml';

    /* Result debug file */
    private string $debug = 'debug.yaml';



    /*
        Constructor builder
    */
    function __construct
    (
        /* Model EPL */
        Epl $aEpl
    )
    {
        /* Set epl object */
        $this -> epl = $aEpl;
        $this -> builder = Builder::create( $this );
    }



    /*
        Create new builder
    */
    public static function create
    (
        /* Model EPL */
        Epl $aEpl
    )
    {
        return new self( $aEpl );
    }



    /*
        Build content from epl model
    */
    public function build
    (
        /* Source files */
        string $aSourceEpl,
        /* Source index file from string */
        string $aIndexFile
    )
    :self
    {
        $this -> getMon() -> now([ 'stat', 'begin' ]);

        /* Build epl */
        $this -> getEpl()
        /* Assemble epl model from epl path */
        -> assemble( $aSourceEpl )
        -> resultTo( $this )
        ;

        /* Build content */
        $this -> buildContent( $aIndexFile );

        /* Dump monitor */
        $this
        -> getMon()
        -> drop( $this -> debug )
        -> flush( $this -> debug )
        ;

        return $this;
    }



    /*
        Build content
    */
    private function buildContent
    (
        $aFile
    )
    {
//        $this -> getTemplate( $aFile );
        return $this;
    }



    /**************************************************************************
        Setters and getters
    */



    /*
        Return epl object
    */
    public function getEpl()
    :Epl
    {
        return $this -> epl;
    }



    /*
        Return application object
    */
    public function getApp()
    :App
    {
        return $this -> getEpl() -> getApp();
    }



    /*
        Return log object
    */
    public function getLog()
    :Log
    {
        return $this -> getApp() -> getLog();
    }



    /*
        Return mon object
    */
    public function getMon()
    :Mon
    {
        return $this -> getApp() -> getMon();
    }



    /*
        Set source path for build epl model
    */
    public function setContentSource
    (
        /* Source path */
        $a
    )
    :self
    {
        $this -> contentSource = $a;
        return $this;
    }



    /*
        Set dest path for build epl model
    */
    public function setDestination
    (
        /* Dst path */
        $a
    )
    :self
    {
        $this -> destination = $a;
        return $this;
    }
}
