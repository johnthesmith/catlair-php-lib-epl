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
    const ENTITY_CONTENT = 'content';
    /* Key name for name of the entity */
    const ENTITY_NAME = 'name';
    /* Key name for hyperlink template of the entity */
    const ENTITY_HYPERLINK = 'hyperlink';
    /* Key name for description of the property */
    const ENTITY_HINT = 'hint';
    /* Key name for description of the property */
    const ENTITY_DESCRIPTION = 'description';
    /* Key name for file with card for entity */
    const ENTITY_CARD = 'card';



    /* Epl object */
    private ?Epl $epl = null;

    /* Result path */
    private ?string $destination = null;

    /* Path from projectResult path */
    private ?string $projectPath = null;

    /* Source path */
    private ?string $source = null;

    /* Result metamodel path */
    private string $metamodel = 'file.yaml';

    /* Result debug file */
    private string $debug = 'debug.yaml';

    /* Entities cards cache */
    private array $cards = [];

    /* Array of waiting lisks */
    private array $todo = [];

    /* Array of completed lins */
    private array $done = [];

    /*
        Create new builder
    */
    public static function create
    (
        /* Model EPL */
        $aEpl
    )
    {
        return new self( $aEpl );
    }



    /*
        Main method for building result documentaiton
    */
    public function run
    (
        /* Source index file from string */
        string $aIndexFile
    )
    :self
    {
        /* Start monitoring */
        $this
        -> getMon()
        -> now([ 'stat', 'begin' ]);

        /* Build epl */
        $this -> getEpl()
        -> assemble( $this -> source )
        -> resultTo( $this );

        /* Let link index */
        $this -> todo = [];
        /* Processed links */
        $this -> done = [];

        /* Start point */
        $this -> addFileTodo( $aIndexFile );

        /* Processing */
        while( !empty( $this -> todo ))
        {
            /* Get first element */
            $hash = array_key_first( $this -> todo );
            $task = $this -> todo[ $hash ];

            unset( $this -> todo[ $hash ]);
            switch( $task[ 'type' ])
            {
                case 'epl':
                    $link = $this -> processEntityLink( $task );
                    break;
                case "file":
                    $link = $this -> processFileLink( $task );
                    break;
                case "external":
                    $link = $this -> processExternalLink( $task );
                    break;
                default:
                    $link = $this -> processUnknownLink( $task );
                    break;
            }
            $this -> done[ $hash ] = $link;
        }

        /* Dump monitor */
        $this
        -> getMon()
        -> drop( $this -> debug )
        -> flush( $this -> debug )
        ;

        return $this;
    }



    /*
        Cretate or find entity link
    */
    private function addEntityLink
    (
        /* Arguments after parsePropertyRef */
        string $aEntityId,
        array $aVector,
        string $aLabel,
        /* Source file with link */
        string $aSource
    )
    : Result
    {
        $result = '';

        if( !$this -> getEpl() -> isEntity( $aEntityId ))
        {
            /* Monitoring */
            $this -> getMon() -> add
            ([
                'error',
                'entity-not-found',
                $aEntityId,
                $source
            ]);
            $result = '`' . $entityId . '`';
        }
        else
        {
            /* Create link hash */
            $hash = $this -> taskHash
            ([
                'type' => 'entity',
                'target' => $aEntityId,
                'label' => $aLabel,
                'vector' => $aVector
            ]);

            /* Check link hash exists */
            if( isset( $this -> done[ $hash ]))
            {
                $result = $this -> done[ $hash ];
            }
            else
            {
                $cardFile = $this -> entityToCardPath( $aEntityId, $aVector );

                $link = self::buildLink
                (
                    empty( $aLabel )
                    ? $this -> getProperty
                    (
                        $aEntityId,
                        [self::ENTITY_NAME],
                        $aEntityId,
                        $aVector
                    )
                    : $aLabel,
                    $cardFile,
                    $this -> getProperty
                    (
                        $aEntityId,
                        [self::ENTITY_HINT],
                        '',
                        $aVector
                    ),
                    $this -> getProperty
                    (
                        $aEntityId,
                        [self::ENTITY_HYPERLINK],
                        '',
                        $aVector
                    )
                );

                /* Store todo */
                $this -> todo[ $hash ] =
                [
                    'type' => 'entity',
                    'target' => $aEntityId,
                    'vector' => $aVector,
                    'label' => $aLabel
                ];

                $result = $link;
            }
        }

        return $result;
    }




    /*
        Build content
    */
    private function buildContentExt
    (
        /* Start content */
        string $aContent,
        /* Current file for relative pathes */
        string $aFile,
        /* Optional entity id */
        string $aIdEntity = null,
        /* Vector */
        string|array $aVector = null
    )
    {
        /* Build content */
        $result = $this -> buildContent
        (
            $aContent,
            false,
            false,
            function ( $content ) use ( $aFile, $aIdEntity, $aVector )
            {
                $result = $content;

                if( $aIdEntity !== null )
                {
                    /* Replace entity properties */
                    /* Extract all %key% patterns with their positions */
                    $pattern = '/%([^%]+)%/';
                    $matches = [];
                    preg_match_all
                    (
                        $pattern,
                        $result,
                        $matches,
                        PREG_OFFSET_CAPTURE
                    );

                    /* Build list of matches */
                    $map =
                    [
                        Epl::ID => $aIdEntity,
                        self::ENTITY_CONTENT => ''
                    ];

                    foreach( $matches[0] as $index => $fullMatch )
                    {
                        $keyStr = $matches[1][$index][0];
                        $ref = Epl::parsePropertyRef
                        (
                            $keyStr,
                            $aIdEntity,
                            [],
                            $aVector
                        );

                        /* Direct property request */
                        $val = $this -> getProperty
                        (
                            $ref[ 'entity' ],
                            $ref[ 'path' ],
                            null,
                            $ref[ 'vector' ]
                        );

                        if( $val === null )
                        {
                            /* failback */
                            $val = $this -> getProperty
                            (
                                $ref[ 'entity' ],
                                [ 'empty-property' ],
                                '`' . $keyStr . '`',
                                $ref[ 'vector' ]
                            );
                        }

                        $map[ $keyStr ] = $val;
                    }
                    $result = clPrep( $result, $map );
                }
                return $result;
            }
        );

        /* Link postprocessing */
        $result = $this -> linkProcessing( $result, $aFile, $aVector );

        /* Build result */
        return $result;
    }


















    /*
        Load and return content from file
    */
    public function getTemplate
    (
        string $aFile
    )
    /* string or null if not exists or reading error */
    :string
    {
        $content = '';
        if( file_exists ( $aFile ))
        {
            $content = file_get_contents( $aFile );
            if( $content !== false )
            {
                $content = trim( $content );
            }
            else
            {
                $this -> getMon() -> set
                (
                    [ 'warning', 'template-read-error' ],
                    $aFile
                );
            }
        }
        else
        {
            $this -> getMon() -> set
            (
                [ 'warning', 'template-not-found' ],
                $aFile
            );
        }
        return $content;
    }



    /*
        Write file in to destination/project folder
    */
    private function writeOutput
    (
        /* Filename for writting with path from project root */
        string $aInternalPath,
        /* Content for writing */
        $aContent
    )
    :bool
    {
        $result = false;

        /* Build entity card file name in the FS */
        $path = clNormalizePath
        (
            implode
            (
                '/',
                [
                    $this -> destination,
                    $this -> projectPath,
                    $aInternalPath
                ]
            )
        );

        $dir = dirname( $path );

        if( clCheckPath( $dir ))
        {
            /* Store file */
            $result = file_put_contents( $path, $aContent );
            if( $result )
            {
                /* Store cache */
                $this -> cards[ $aInternalPath ] = true;
            }
        }
        else
        {
            $this -> getMon() -> add
            (
                [ 'warning', 'error-create-dir', $dir ]
            );
        }

        return $result;
    }



    /*
        Return link in selected format
    */
    static private function buildLink
    (
        string $aLabel,
        string $aLink,
        string $aHint = '',
        string $aTemplate = '[%label%](%link% "%hint%")'
    )
    :string
    {
        return clPrep
        (
            $aTemplate,
            [
                'label' => $aLabel,
                'link' => $aLink,
                'hint' => $aHint
            ]
        );
    }



    /*
        Return FS path from source for value
    */
    private function resolveToFsPath
    (
        /* Value
            ../ ./ - rellative path from current
            any char - relative path from source
        */
        string $aValue,
        /* Optional FS path fith link source */
        string $aCurrent = ''
    )
    :string
    {
        if
        (
            strpos( $aValue, './' ) === 0 ||
            strpos( $aValue, '../' ) === 0
        )
        {
            /* Local relative to source: ./link.md */
            $result = $aCurrent . '/' . $aValue;
        }
        else
        {
            /* Relative to project docs/card.md */
            $result = $this -> source . '/' . $aValue;
        }
        return $result;
    }




    /*
        Resolve link to path relative to project root
    */
    private function resolveToProjectPath
    (
        string $aLink,
        string $aCurrent
    )
    : string
    {
        if
        (
            strpos( $aLink, './' ) === 0 ||
            strpos( $aLink, '../' ) === 0
        )
        {
            $result = dirname( $aCurrent ) . '/' . $aLink;
        }
        else
        {
            $result = $aLink;
        }
        return $result;
    }




    /*
        Resolve property value with processing of special prefixes
    */
    private function resolvePropertyValue
    (
        /* Value for resolving */
        mixed $aValue,
        /* source file path */
        string $aSource,
        /* current entity */
        string $aEntityId,
        /* current property path */
        array $aKeyPath,
        /* default value */
        $aDefault,
        /* current vector */
        $aVector
    )
    : mixed
    {
        $result = $aDefault;
        /* Escape symbol ~ */
        if( is_string( $aValue ) && $aValue[0] === '~' )
        {
            $result = substr( $aValue, 1 );
        }
        /* Property reference p:... */
        elseif( is_string( $aValue ) && strpos( $aValue, 'p:' ) === 0 )
        {
            $ref = Epl::parsePropertyRef
            (
                substr( $aValue, 2 ),
                $aEntityId,
                $aKeyPath,
                $aVector
            );
            $result = $this -> getProperty
            (
                $ref[ 'entity' ],
                $ref[ 'path' ],
                $aDefault,
                $ref[ 'vector' ]
            );
        }
        /* File reference f:... */
        elseif( is_string( $aValue ) && strpos( $aValue, 'f:' ) === 0 )
        {
            $filePath = substr( $aValue, 2 );
            $filePath = $this -> resolveToFsPath
            (
                $filePath,
                dirname( $aSource )
            );
            $realPath = realpath( $filePath );
            if( empty( $realPath ))
            {
                $this -> getMon() -> add
                (
                    [
                        'warning',
                        'include-file-not-found',
                        $filePath
                    ]
                );
            }
            else
            {
                $result = $this -> getTemplate( $filePath );
            }
        }
        /* Plain value */
        else
        {
            $result = $aValue;
        }
        return $result;
    }



    /*
        Return real property with file content or entity path vector
        for
            `f:` prefix, content will be loaded from file.
            `p:` prefix, content will be loaded from any property

        Method define processing file with property
    */
    public function getProperty
    (
        /* Entity identifier */
        string $aIdEntity,
        /* Key path: slash-separated string or array of segments */
        array $aKeyPath,
        /* Default value */
        $aDefault = '',
        /* Vector null | [ key:value ] | [ key:[ value,value ]] */
        string|array $aVector = null
    )
    :mixed
    {
        /* Virtual properties */
        if( count( $aKeyPath ) === 1 )
        {
            switch( $aKeyPath[0] )
            {
                case 'id':
                    return $aIdEntity;
                case 'type':
                    return $this -> getEpl() -> getEntityType( $aIdEntity );
            }
        }

        /* Read property */
        $property = $this -> getEpl() -> getProperty
        (
            $aIdEntity,
            $aKeyPath,
            $aVector
        );

        return empty( $property )
        ? $aDefault
        : $this -> resolvePropertyValue
        (
            $property[ 'value' ],
            $property[ 'source' ],
            $aIdEntity,
            $aKeyPath,
            $aDefault,
            $aVector
        );
    }



    /*
        Extract all [label](link) from content
    */
    private function linkProcessing
    (
        /* Content for processing */
        string $content,
        /* Processing file name for relative links */
        string $aSourceFile,
        /* Default vector for files porcessing */
        string|array $aVector = null
    )
    :string
    {
        $pattern = '/\[(.*?)\]\((.*?)\)/';
        $links = [];

        /* find all atches */
        preg_match_all
        (
            $pattern,
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        /* Build array */
        foreach( $matches as $match )
        {
            $links[] =
            [
                'full'  => $match[0][0],
                'label' => $match[1][0],
                'link'  => $match[2][0],
                'start' => $match[0][1],
                'end'   => $match[0][1] + strlen($match[0][0])
            ];
        }

        /* Links processing */
        $processedLinks = [];
        foreach( $links as $item )
        {
            $link = $item[ 'link' ];
            $label = $item[ 'label' ];
            $resolved = '';

            /* Protocol check (external URL) */
            if( preg_match( '#^(https?|ftp|mailto)://#', $link ))
            {
                $resolved = self::buildLink( $label, $link );
                /* Url processing */
            }
            else
            {
                /* Local anchor */
                if( strpos( $link, '#' ) === 0 )
                {
                    $resolved = $link;
                }
                else
                {
                    /*
                        Entity [](entity)
                    */
                    /* Convert link reference from string  */
                    $ref = Epl::parsePropertyRef( $link );
                    if( $this -> getEpl() -> isEntity( $ref[ 'entity' ] ))
                    {
                        $resolved = $this -> buildEntityLink
                        (
                            $ref[ 'entity' ],
                            $item[ 'label' ],
                            $ref[ 'vector' ]
                        );
                    }
                    else
                    {
                        /*
                            File [](file)
                        */
                        /* Split anchor */
                        $parts = explode( '#', $link, 2 );
                        $file = $parts[ 0 ];
                        $ancor = $parts[ 1 ] ?? '';

                        /* Resolve link file relative file */
                        $file = $this -> resolveToProjectPath
                        (
                            $file,
                            $aSourceFile,
                        );

                        $sourceFile = clNormalizePath
                        (
                            $this -> source . '/' . $file
                        );

                        /* Check source file */
                        if( file_exists( $sourceFile ))
                        {
                            /* File from link processing */
                            $this -> processingFile( $file, $aVector );
                            /* Build link */
                            $resolved = self::buildLink
                            (
                                $item[ 'label' ],
                                $link
                            );
                        }
                        else
                        {
                            /* Unknown link */
                            $resolved = 'unknown-link:' . $link . '`';
                            $this
                            -> getMon()
                            -> add
                            (
                                [ 'warning', 'unknown-link', $link, $aSourceFile ]
                            );
                        }
                    }
                }
            }

            $processedLinks[] =
            [
                'content'   => $resolved,
                'start'     => $item[ 'start' ],
                'end'       => $item[ 'end' ]
            ];
        }

        /* Replace links in content */
        for( $i = count( $processedLinks ) - 1; $i >= 0; $i-- )
        {
            $link = $processedLinks[ $i ];
            $content = substr_replace
            (
                $content,
                $link[ 'content' ],
                $link[ 'start' ],
                $link[ 'end' ] - $link[ 'start' ]
            );
        }

        return $content;
    }




    /*
        Build entity link and card
        Return it

        Using cards cache
    */
    private function buildEntityLink
    (
        /* Entity id */
        $aId,
        /* Optional label */
        string $aLabel,
        /* Vector for name request */
        array $aVector
    )
    : ?string
    {
        /*  Default link */
        $result = null;

        if( $this -> getEpl() -> isEntity( $aId ) )
        {
            /* Build entity card hash */
            $cardFile = implode
            (
                '',
                [
                    '/card/',
                    $aId,
                    '/',
                    Epl::vectorToString( $aVector ),
                    '.md'
                ]
            );

            /*
                Build card
            */
            /* Check card file in the cache */
            if( !( $this -> cards[ $cardFile ] ?? false ))
            {
                /* Get card content */
                $content = $this -> getProperty
                (
                    $aId,
                    [ self::ENTITY_CARD ],
                    '',
                    $aVector
                );

                /* Build content */
                $content = $this -> buildContentExt
                (
                    $content,
                    $cardFile,
                    $aId,
                    $aVector
                );

                /* Write content */
                if( $this -> writeOutput( $cardFile, $content ))
                {
                    /*
                        Build link
                    */
                    $result = self::buildLink
                    (
                        /* Label */
                        empty( $aLabel )
                        ? $this -> getProperty
                        (
                            $aId,
                            [ self::ENTITY_NAME ],
                            $aId,
                            $aVector
                        )
                        : $aLabel,

                        /* Link */
                        $cardFile,

                        $this -> getProperty
                        (
                            $aId,
                            [ self::ENTITY_HINT ],
                            '',
                            $aVector
                        ),

                        $this -> getProperty
                        (
                            $aId,
                            [ self::ENTITY_HYPERLINK ],
                            '',
                            $aVector
                        )
                    );
                }
            }
        }

        return $result;
    }



    /*
        Build file and return content
    */
    private function processingFile
    (
        /* Source file name with content for build */
        string $aFile,
        /* Vector for build content */
        string|array $aVector = []
    )
    :bool
    {
        $sourceFile = $this -> source . '/' . $aFile;
        /* Get content from file */
        $content = $this -> getTemplate( $sourceFile );

        if( !empty( $content ))
        {
            /* Get file extension */
            $ext = strtolower( pathinfo( $aFile, PATHINFO_EXTENSION ));

            /* Processing */
            switch( $ext )
            {
                case 'md':
                case 'txt':
                case 'svg':
                    /* Rebuild content with template processing */
                    $content = $this -> buildContentExt
                    (
                        $content,
                        $aFile,
                        null,
                        $aVector
                    );
                    break;
                default: break;
            }

            /* Return content */
            $this -> writeOutput( $aFile, $content );
        }

        return !empty( $content );
    }


    /**************************************************************************
        Setters and getters
    */



    /*
        Return epl object
    */
    public function getEpl()
    {
        return $this -> getOwner();
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



    /*
        Set project path
    */
    public function setProjectPath
    (
        /* Dst path */
        $a
    )
    :self
    {
        $this -> projectPath = $a;
        return $this;
    }



    /*
        Set dest path for build epl model
    */
    public function setSource
    (
        /* Source path */
        $a
    )
    :self
    {
        $this -> source = $a;
        return $this;
    }
}
