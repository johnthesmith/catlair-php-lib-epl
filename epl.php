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
    /* Key name for entities */
    const ENTITIES = 'e';

    /* Key name for properties */
    const PROPERTIES = 'p';

    /* Key name for links */
    const LINKS = 'l';

    /* Key name for id field */
    const ID = 'id';

    /* Key name for context field */
    const CONTEXT = 'context';

    /* Key name for from field (links) */
    const FROM = 'from';

    /* Key name for to field (links) */
    const TO = 'to';

    /* Addition properties for link */
    const PROPERTIES = 'properties';

    /* Label for link */
    const LABEL = 'label';

    /* Key name for private properties */
    const PRIVATE = 'private';

    /* Key name for public properties */
    const PUBLIC  = 'public';

    /* Key name for type value */
    const TYPE  = 'type';

    /* Key name for source value */
    const SOURCE  = 'source';

    /*
        Array of entities:
        [
            entity_id =>
            [
                'type' => type,
                'source' => source
            ]
        ]
    */
    private array $entities = [];

    /*
        Array of properties:
        list of
        [
            'id' => entity_id,
            'context' => ...,
            'private' => [...],
            'public' => [...],
            'source' => ...
        ]
    */
    private array $properties = [];

    /*
        Array of links:
        list of
        [
            'from' => ...,
            'to' => ...,
            'type' => ...,
            'label' => ...,
            'context' => ...,
            'properties' => [...],
            'source' => ... ]
    */
    private array $links = [];


    /* Root of entity */
    private string $root = 'entity';



    /*
        New object
    */
    function __construct
    (
        App $aApp
    )
    {
        parent::__construct();
        $this -> app = $aApp;
        $this -> clear();
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
        Utils
    */


    /*
        Clear facts
    */
    public function clear()
    {
        $this -> entities = [];
        $this -> properties = [];
        $this -> links = [];
        $this -> version = '';
        return $this;
    }



    /*
        Load facts from file
    */
    public function read
    (
        /* File name */
        string $aFile
    )
    {
        return $this;
    }



    /*
        Write facts in to file
    */
    public function write
    (
        /* File name for writing */
        string $aFile
    )
    {
        return $this;
    }



    /*
        Assemble facts from files json || yaml
    */
    public function assemble
    (
        /* Path with files */
        string $aPath
    )
    {
        return $this;
    }



    /**************************************************************************
        Work with entities
    */

    /*
       Get reference to entities array
    */
    private function &getEntities()
    :array
    {
        return $this -> entities;
    }



    /*
       Get reference to properties array
    */
    private function &getProperties()
    :array
    {
        return $this -> properties;
    }



    /*
       Get reference to links array
    */
    private function &getLinks()
    :array
    {
        return $this -> links;
    }



    /*
       Check exists entity
    */
    public function isEntity
    (
        /* Entity id */
        string $aId
    )
    :bool
    {
        return isset( $this -> entities[ $aId ] );
    }



    /*
        Return type of entity
    */
    public function getEntityType
    (
        /* Entity id */
        string $aId
    )
    :?string
    {
        return $this -> entities[ $aId ][ self::TYPE ] ?? null;
    }



    /*
        Add new entity
    */
    public function addEntity
    (
        /* Entity id */
        string $aId,
        /* Entity type */
        string $aType = null,
        /* Source */
        string $aSource = null
    )
    :self
    {
        $this -> entities[ $aId ] =
        [
            self::TYPE => empty( $aType ) ? $this -> root : $aType,
            self::SOURCE => $aSource
        ];
        return $this;
    }



    /**************************************************************************
        Work with entities
    */

    public function addRawProperties
    (
        /* Entity id */
        string $aEntityId,
        /* Property data array, must contain 'id' key */
        array $aValues,
        /* Visibility */
        string $aScope = self::PUBLIC,
        /* Context */
        string|array $aContext = null,
        /* Source */
        string $aSource = null
    )
    :self
    {
        $this -> properties[] =
        [
            self::ID => $aEntityId,
            self::CONTEXT => $aContext,
            self::SOURCE => $aSource,
            $aScope => $aValues
        ];
        return $this;
    }


    /**************************************************************************
        Work with links
    */


    /*
       Add link between entities
    */
    public function addRawLink
    (
        /* From entity id */
        string $aFromId,
        /* To entity id */
        string $aToId,
        /* Link type */
        string $aType,
        /* Label for link */
        string $aLabel = null,
        /* Link properties array */
        array $aProperties = [],
        /* Context */
        string|array $aContext = null,
        /* Source file */
        string $aSource = null
    )
    :self
    {
        $this -> links[]
        = [
            self::FROM => $aFromId,
            self::TO => $aToId,
            self::TYPE => $aType,
            self::LABEL => $aLabel,
            self::CONTEXT => $aContext,
            self::SOURCE => $aSource,
            self::PROPERTIES => $aProperties
        ];
        return $this;
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
