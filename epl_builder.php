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
        Build content from epl model

        run
            buildFile
                buildContentExt
                    linkProcessing
                        |entity:
                            buildEntityLink, buildContentExt, writeOutput
                        |file:
                        |url:
                            buildLink
    */
    public function run
    (
        /* Source index file from string */
        string $aIndexFile
    )
    :self
    {
        $this
        -> getMon()
        -> now([ 'stat', 'begin' ]);

        /* Reset cards cache */
        $this -> cards = [];

        /* Build epl */
        $this -> getEpl()
        /* Assemble epl model from epl path */
        -> assemble( $this -> source )
        -> resultTo( $this )
        ;

        /* Build first file */
        $this -> buildFile( $this -> source . '/' . $aIndexFile );

        /* Dump monitor */
        $this
        -> getMon()
        -> drop( $this -> debug )
        -> flush( $this -> debug )
        ;

        return $this;
    }



    /*
        Load and return content
    */
    public function getTemplate
    (
        string $aFile
    )
    :string
    {
        $content = trim( file_get_contents( $aFile ));
        if( empty( $content ))
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
        Write file in to destination folder
    */
    private function writeOutput
    (
        /* Hash for content */
        $aHash,
        /* Content file */
        $aContent
    )
    /* File name */
    :string
    {
        /* Generate project path for file */
        $local = '/' . $this -> projectPath . clScatterName( $aHash, 3 ) . '.md';

        /* Build entity card file name in the FS*/
        $path = $this -> destination . $local;

        if( clCheckPath( dirname( $path )))
        {
            /* Store file */
            if( file_put_contents( $path, $aContent ))
            {
                /* Store hash */
                $this -> cards[ $aHash ] = true;
            }
        }

        return $local;
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
        Return project, absolute or relative path for value
        if value return from:
            / - absolute path (current doesn't use)
            ./ - rellative path (current/value)
            else - relative path from root (source/value)
    */
    static private function resolveRelativePath
    (
        /* Value */
        string $aValue,
        /* Optional current path for calculate result */
        string $aCurrent = '',
        /* Project path */
        string $aProject = ''
    )
    :string
    {
        /* Determine path type */
        if( $aValue[ 0 ] === '/' )
        {
            /* Absolute path: /etc/config.json */
            $result = $aValue;
        }
        elseif
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
            /* Relative to project root: docs/card.md */
            $result = $aProject . '/' . $aValue;
        }
        return $result;
    }



    /*
        Return real property with file content
        If propery contains `file:` prefix,
        content will be loaded from file.

        Method define processing file with property
    */
    public function getProperty
    (
        /* Entity identifier */
        string $aIdEntity,
        /* Key path: dot-separated string or array of segments */
        string|array $aKeyPath,
        /* Default value */
        $aDefault = '',
        /* Vector null | [ key:value ] | [ key:[ value,value ]] */
        string|array $aVector = null
    )
    :mixed
    {
        $result = $aDefault;

        /* Read property */
        $property = $this -> getEpl() -> getProperty
        (
            $aIdEntity,
            $aKeyPath,
            $aVector
        );

        if( !empty( $property ))
        {
            $source = $property[ 'source' ];
            $value = $property[ 'value' ];

            if( is_string( $value ) && strpos( $value, 'file:' ) === 0 )
            {
                /* Remove 'file:' */
                $filePath = substr( $value, 5 );

                /* Return file */
                $filePath = $this -> resolveRelativePath
                (
                    $filePath,
                    dirname( $source ),
                    $this -> source
                );

                /* Resolve real path for file */
                $realPath = realpath( $filePath );

                /* Check real path exists */
                if( empty( $realPath ))
                {
                    $this -> getMon() -> add
                    (
                        [
                            'warning',
                            'include-file-not-fond',
                            $filePath
                        ]
                    );
                }
                else
                {
                    /* Return file contnet */
                    $result = $this -> getTemplate( $filePath );
                }
            }
            else
            {
                $result = $value;
            }
        }

        return $result;
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
        $aLabel,
        /* Vector for name request */
        $aVector
    )
    : ?string
    {
        /*  Default link */
        $result = null;
        if( $this -> getEpl() -> isEntity( $aId ) )
        {
            /* Build entity card hash */
            $hash = hash( 'sha256', $aId . serialize( $aVector ));

            /*
                Build card
            */
            /* Check entity hash */
            if( !( $this -> cards[ $hash ] ?? false ))
            {
                /* Get card content */
                $content = $this -> getProperty( $aId, Epl::CARD, '', $aVector );
                /* Build content */
                $content = $this -> buildContentExt( $content, 'CHECK_', $aId, $aVector );
                /* Write content */
                $filename = $this -> writeOutput( $hash, $content );

                if( !empty( $filename ))
                {
                    /*
                        Build link
                    */
                    $result = self::buildLink
                    (
                        /* Label */
                        empty( $aLabel )
                        ? $this -> getProperty( $aId, self::ENTITY_NAME, $aId, $aVector )
                        : $aLabel,

                        /* Link */
                        $filename,

                        $this -> getProperty( $aId, self::ENTITY_HINT, '', $aVector ),
                        $this -> getProperty( $aId, self::ENTITY_HYPERLINK, '', $aVector )
                    );
                }
            }
        }
        return $result;
    }



    /*
        Extract all [label](link) from content
    */
    private function linkProcessing
    (
        /* Content for processing */
        string $content,
        /* Processing file name for relative links */
        string $aFile
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
                    /* Entity */
                    /* Split for vector */
                    $parts = explode( '|', $link, 2 );
                    $entity = $parts[ 0 ];

                    if( $this -> getEpl() -> isEntity( $entity ))
                    {
                        $resolved = $this -> buildEntityLink
                        (
                            $link,
                            $item[ 'label' ],
                            /* Vector */
                            $parts[ 1 ] ?? ''
                        );
                    }
                    else
                    {
                        /* File */
                        /* Split anchor */
                        $parts = explode( '#', $link, 2 );
                        $file = $parts[ 0 ];
                        $ancor = $parts[ 1 ] ?? '';
                        /* Resolve relative file neme from $aFile source */
                        $file = self::resolveRelativePath
                        (
                            $file,
                            dirname( $aFile )
                        );
                        /* Check file */
                        if( file_exists( $file ))
                        {
                            /* File processing */
                            if( true )
                            {
                                $resolved = self::buildLink
                                (
                                    /* Label */
                                    $item[ 'label' ],
                                    $link
                                );
                            }
                        }
                        else
                        {
                            /* Unknown link */
                            $resolved = '`unknown-link:' . $link . '`';
                            $this
                            -> getMon()
                            -> add
                            (
                                [ 'warning', 'unknown-link', $aFile, $link ]
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
                    $pattern = '/%([\w\.]+)%/';
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

                    foreach ($matches[0] as $index => $fullMatch)
                    {
                        $key = $matches[1][$index][0];
                        $value = $this -> getProperty
                        (
                            $aIdEntity, $key, null, $aVector
                        );
                        if( $value !== null ) $map[ $key ] = $value;
                    }
                    $result = clPrep( $result, $map );
                }
                return $result;
            }
        );

        /* Link postprocessing */
        $result = $this -> linkProcessing( $result, $aFile );

        /* Build result */
        return $result;
    }



    /*
        Build file and return content
    */
    private function buildFile
    (
        /* Source file name with content for build */
        string $aFile,
        string|array $aVector = []
    )
    :self
    {
        /* Get content from file */
        $content = $this -> getTemplate( $aFile );
        /* Get file extension */
        $ext = strtolower(pathinfo($aFile, PATHINFO_EXTENSION));

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
        $this -> writeOutput( hash( 'sha256', $aFile), $content );

        return $this;
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
