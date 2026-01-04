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
    Module for working with Catlair EPL (Entity Property Link) model.
    Implements core methods for model assembly and provides an interface
    for interacting with it.

    2026.01.01 - still@itserv.ru
*/


namespace catlair;



/*
    Libraries
*/
require_once( LIB . '/core/result.php' );
require_once( LIB . '/core/mon.php' );
require_once( LIB . '/core/cp.php' );
require_once( LIB . '/app/app.php' );
require_once( LIB . '/web/serialize.php' );



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
            entity_id:
            {
                type: type,
                source: source
            }
        ]
    */
    private array $entities = [];

    /*
        Array of properties:
        [
            entity_id:
            {
                context: ...,
                private: [...],
                public: [...],
                source: ...
            }
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


    /* Monitoring object */
    private ?Mon $mon = null;


    /*
        New object
    */
    function __construct
    (
        App $aApp
    )
    {
        $this -> app = $aApp;
        $this -> mon = Mon::create( $this -> getLog() );
        $this -> clear();
    }



    /*
        Create and return new entity object
    */
    static public function create
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
       Assemble facts from files json || yaml
    */
    public function assemble
    (
        /* Path with files */
        string $aPath
    )
    :self
    {
        /* */
        $this -> mon -> drop( './assemble.json' );

        /* Check path accessibility */
        if( !is_dir( $aPath ) || !is_readable( $aPath ) )
        {
            $this -> setResult
            (
                'epl::assemble:path-error',
                [
                    'message' => 'Path not accessible',
                    'path' => $aPath
                ]
            );
            return $this;
        }

        try
        {
            $dir = new \RecursiveDirectoryIterator
            (
                $aPath,
                \RecursiveDirectoryIterator::SKIP_DOTS |
                \RecursiveDirectoryIterator::KEY_AS_PATHNAME
            );

            $iterator = new \RecursiveIteratorIterator
            (
                $dir,
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach( $iterator as $file )
            {
                try
                {
                    if( $file->isFile() )
                    {
                        $ext = $file -> getExtension();
                        if( $ext === 'json' || $ext === 'yaml' || $ext === 'yml' )
                        {
                            $content = @file_get_contents( $file -> getPathname() );
                            if( $content === false )
                            {
                                $this -> mon -> add
                                (
                                    [
                                        'warning',
                                        'file-read',
                                        $file -> getPathname()
                                    ]
                                );
                                continue;
                            }

                            $r = new Result();
                            $data = clParse( $content, $ext, $r );
                            if( $r -> isOk() )
                            {
                                if(( $data[ 'enabled' ] ?? true ) !== false )
                                {
                                    $this -> ingest( $data, $file->getPathname() );
                                }
                            }
                            else
                            {
                                $this -> mon -> add
                                (
                                    [
                                        'warning',
                                        'file-parse-error',
                                        $r -> getCode(),
                                        $file -> getPathname()
                                    ]
                                );
                            }
                        }
                    }
                }
                catch( \Exception $e )
                {
                    $this -> setResult
                    (
                        'Epl::assemble:iterator-error',
                        [
                            'message' => $e -> getMessage(),
                            'path' => $aPath
                        ]
                    );
                    continue;
                }
            }
        }
        catch( \Exception $e )
        {
            $this -> setResult
            (
                'Epl::assemble:iterator_error',
                [ 'message' => $e -> getMessage() ]
            );
        }

        /* Postprocessing */
        foreach( $this -> entities as $entityId => $entityData )
        {
            $type = $entityData[ self::TYPE ] ?? null;
            if( !$this -> isEntity( $type ))
            {
                $this -> mon -> add([ 'warning', 'error-check-type', $type ]);
            }
        }

        /* Dump mon */
        $this -> mon -> flush( './assemble.json' );

        return $this;
    }



    /*
       Ingest data array into EPL
    */
    public function ingest
    (
        /* Data array with keys e, p, l */
        array $aData,
        /* Source file name */
        string $aSource
    )
    :self
    {
        /* Entities */
        if( isset( $aData[ self::ENTITIES ] ))
        {
            if( is_array( $aData[ self::ENTITIES ] ))
            {
                foreach( $aData[ self::ENTITIES ] ?? [] as $id => $type )
                {
                    $this -> addEntity( $id, $type, $aSource );
                }
            }
            else
            {
                $this -> mon -> add
                (
                    [
                        'entities-is-not-array',
                        $aSource
                    ]
                );
            }
        }

        /* Properties */
        /* Check exists key */
        if( isset( $aData[ self::PROPERTIES ] ))
        {
            if( !is_array( $aData[ self::PROPERTIES ] ))
            {
                $this -> mon -> add
                (
                    [
                        'properties-is-not-array',
                        $aSource
                    ]
                );
            }
            else
            {
                if
                (
                    !empty
                    (
                        array_filter
                        (
                            array_keys( $aData[ self::PROPERTIES ] ),
                            'is_string'
                        )
                    )
                )
                {
                    $this -> mon -> add
                    (
                        [
                            'properties-is-not-index-array',
                            $aSource
                        ]
                    );
                }
                else
                {
                    foreach( $aData[ self::PROPERTIES ] ?? [] as $item )
                    {
                        if( !empty( $item[ self::ID ] ?? null ))
                        {
                            $this -> addRawProperties
                            (
                                $item[ self::ID ] ?? '',
                                $item[ self::PRIVATE ] ?? [],
                                $item[ self::PUBLIC ] ?? [],
                                $item[ self::CONTEXT ] ?? null,
                                $aSource
                            );
                        }
                        else
                        {
                            $this -> mon -> add
                            (
                                [
                                    'property-id-not-found',
                                    $aSource
                                ]
                            );
                        }
                    }
                }
            }
        }

        /* Links */
        if( isset( $aData[ self::LINKS ] ))
        {
            if( !is_array( $aData[ self::LINKS ] ))
            {
                $this -> mon -> add
                (
                    [
                        'links-is-not-array',
                        $aSource
                    ]
                );
            }
            else
            {
                if
                (
                    !empty
                    (
                        array_filter
                        (
                            array_keys( $aData[ self::LINKS ] ),
                            'is_string'
                        )
                    )
                )
                {
                    $this -> mon -> add
                    (
                        [
                            'link-is-not-index-array',
                            $aSource
                        ]
                    );
                }
                else
                {
                    foreach( $aData[ self::LINKS ] ?? [] as $link )
                    {
                        $this -> addRawLink
                        (
                            $link[ self::FROM ],
                            $link[ self::TO ],
                            $link[ self::TYPE ],
                            $link[ self::LABEL ] ?? null,
                            $link[ self::PROPERTIES ] ?? [],
                            $link[ self::CONTEXT ] ?? null,
                            $aSource
                        );
                    }
                }
            }
        }

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
       Build exportable data array (without source)
    */
    public function export()
    :array
    {
        $data = [];

        /* Сущности */
        $data[ self::ENTITIES ] = [];
        foreach( $this->entities as $id => $entity )
        {
            $data[ self::ENTITIES ][ $id ] = $entity[ self::TYPE ] ?? '';
        }

        /* Свойства */
        $data[ self::PROPERTIES ] = [];
        foreach( $this -> properties as $id => $properties )
        {
            foreach( $properties as $item )
            {
                $export = [ self::ID => $id ];
                if( !empty( $item[ self::PRIVATE ] ))
                {
                    $export[ self::PRIVATE ] = $item[ self::PRIVATE ];
                }
                if( !empty( $item[ self::PUBLIC ] ))
                {
                    $export[ self::PUBLIC ] = $item[ self::PUBLIC ];
                }
                if( !empty( $item[ self::CONTEXT ] ))
                {
                    $export[ self::CONTEXT ] = $item[ self::CONTEXT ];
                }
                /* Add result record */
                $data[ self::PROPERTIES ][] = $export;
            }
        }

        /* Связи */
        $data[ self::LINKS ] = [];
        foreach( $this->links as $link )
        {
            $clean = $link;
            unset( $clean[ self::SOURCE ] );
            $data[ self::LINKS ][] = $clean;
        }

        return $data;
    }



    /*
        Return serialized EPL
    */
    public function toString
    (
        /* Format MIME::**/
        string $aFormat = Mime::YAML
    ): string
    {
        $data = $this -> export();
        $result = clSerialize( $data, $aFormat );
        return $result;
    }



    /*
       Write facts to file
    */
    public function write
    (
        string $aFile
    )
    :self
    {
        $data = $this -> export();
        $ext = pathinfo( $aFile, PATHINFO_EXTENSION );
        $content = $this -> serializeData( $data, $ext );

        file_put_contents( $aFile, $content );

        return $this;
    }




    /*
       Normalize context to array of string arrays
    */
    private function normalizeContext
    (
        /* Raw context: string, string[], string[][] or null */
        $aRawContext
    )
    :array
    {
        $result = [];

        /* Null context */
        if( $aRawContext === null )
        {
            $result = [];
        }
        /* String context */
        elseif( is_string( $aRawContext ) )
        {
            $result = [ [ $aRawContext ] ];
        }
        /* Array context */
        elseif( is_array( $aRawContext ) )
        {
            /* Empty array */
            if( empty( $aRawContext ) )
            {
                $result = [ [] ];
            }
            else
            {
                /* Check first element type */
                $first = reset( $aRawContext );
                /* Already array of arrays */
                if( is_array( $first ) )
                {
                    $result = $aRawContext;
                }
                /* Array of strings */
                else
                {
                    $result = [ $aRawContext ];
                }
            }
        }
        // Fallback
        else
        {
            $result = [ [] ];
        }

        return $result;
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



    /*
        Iterate over parent entities starting from given entity ID.
        Yields: entityId, entityType, entityData
    */
    private function parentIterator
    (
        string $startEntityId
    )
    : \Generator
    {
        $currentId = $startEntityId;
        $visited = [];
        while( $currentId !== null && !isset( $visited[ $currentId ] ) )
        {
            $visited[ $currentId ] = true;
            $entity = $this->entities[ $currentId ] ?? null;
            if( $entity === null ) break;
            yield $currentId => $entity;
            $nextId = $entity[ self::TYPE ] ?? null;
            if( $nextId === $currentId ) break;
            $currentId = $nextId;
        }
    }



    /**************************************************************************
        Work with properties
    */

    /*
        Add property record
    */
    public function addRawProperties
    (
        /* Entity id */
        string $aEntityId,
        /* Private values */
        array $aPrivate,
        /* Public values*/
        array $aPublic,
        /* Context */
        string|array $aContext = null,
        /* Source file */
        string $aSource = null
    )
    :self
    {
        $this -> properties[ $aEntityId ][] =
        [
            self::CONTEXT => $this -> normalizeContext( $aContext ),
            self::PRIVATE => $aPrivate,
            self::PUBLIC  => $aPublic,
            self::SOURCE  => $aSource
        ];
        return $this;
    }



    /*
        Return property value for entity by key path and context with CP-12 rule
       matching. Inheritance: private properties of current entity, then public
       properties of parent entities.
    */
    public function getProperty
    (
        /* Entity identifier */
        string $aIdEntity,
        /* Key path: dot-separated string or array of segments */
        string|array $aKeyPath,
        /* Default value returned if property is not found */
        mixed $aDefault = null,
        /* Context for property filtering "" | [""] | [[""]] */
        string|array $aContext = null,
        /* CP-12 rule constant for context matching */
        int $aCp12 = Cp::AND_OR_EQUALS
    )
    :mixed
    {
        $result = null;
        /* Prepare key context */
        $keyContext = $this -> normalizeContext( $aContext );
        /* Retrive properties */
        $props = $this -> properties[ $aIdEntity ] ?? [];
        /* Normalize key */
        $path = is_array( $aKeyPath ) ? $aKeyPath : [ $aKeyPath ];
        /* Search in properties of the current entity */
        foreach( $props as $item )
        {
            if
            (
                Cp::check
                (
                    $keyContext,
                    $item[ self::CONTEXT ],
                    $aCp12
                )
            )
            {
                if( clValueExists( $item[ self::PRIVATE ] ?? [], $path ))
                {
                    $result = clValueFromObject
                    (
                        $item[ self::PRIVATE ],
                        $path
                    );
                    break;
                }
            }
        }

        if( $result === null )
        {
            /* Find public properties from parents */
            foreach
            (
                $this -> parentIterator( $aIdEntity )
                as $parentId => $parentEntity
            )
            {
                $props = $this -> properties[ $parentId ] ?? [];
                foreach( $props as $item )
                {
                    if
                    (
                        Cp::check
                        (
                            $keyContext,
                            $item[ self::CONTEXT ],
                            $aCp12
                        )
                    )
                    {
                        if( clValueExists( $item[ self::PUBLIC ] ?? [], $path ))
                        {
                            $result = clValueFromObject
                            (
                                $item[ self::PUBLIC ],
                                $path
                            );
                            break 2;
                        }
                    }
                }
            }
        }

        return $result ?? $aDefault;
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
            self::CONTEXT => $this -> $aContext,
            self::SOURCE => $aSource,
            self::PROPERTIES => $aProperties
        ];
        return $this;
    }



    /**************************************************************************
        Setters and getters
    */


    /*
        Return log object from app
    */
    public function getLog()
    :Log
    {
        return $this -> app -> getLog();
    }
}
