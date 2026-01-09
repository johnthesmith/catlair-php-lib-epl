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
    private ?Epl $epl = null;

    /* Result path */
    private ?string $destination = null;

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

        $this -> buildFile( $this -> source . '/' . $aIndexFile );


        /* Dump monitor */
        $this
        -> getMon()
        -> drop( $this -> debug )
        -> flush( $this -> debug )
        ;

        return $this;
    }



    public function getTemplate
    (
        string $aFile
    )
    :string
    {
        return file_get_contents( $aFile );
    }



    /*
        Write file in to destination folder
    */
    private function writeOutputFile
    (
        /* Local file from destination path */
        $aLocal,
        /* Content file */
        $aContent
    )
    {
        $path = $this -> destination  . '/' . $aLocal;

        $dir = dirname( $path );
        $file = basename( $path );

        $parts = explode('/', $dir);
        $stack = [];
        foreach( $parts as $p )
        {
            if( $p === '' || $p === '.' ) continue;
            if( $p === '..' ) array_pop($stack);
            else $stack[] = $p;
        }
        $path = implode( '/', $stack );

        if( clCheckPath( $path ))
        {
            $fullpath = $path . '/' . $file;
            /* Store file */
            file_put_contents( $fullpath, $aContent );
        }
        return $this;
    }



    /*
        Return link in selected format
    */
    static private function buildLink
    (
        string $aLabel,
        string $aLink,
        string $aHint = '',
        string $aTemplate = '[%label%](%link%|%hint%)'
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
        Return real property with file content
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

                /* Determine path type */
                if( $filePath[0] === '/' )
                {
                    /* Absolute path: file:/etc/config.json */
                }
                elseif( strpos( $filePath, './' ) === 0 )
                {
                    /* Local relative to source: file:./link.md */
                    $filePath = dirname( $source )
                    . '/'
                    . substr( $filePath, 2 );
                }
                else
                {
                    /* Relative to project root: file:docs/card.md */
                    $filePath = $this -> source . '/' . $filePath;
                }

                /* Resolve real path for file */
                $realPath = realpath( $filePath );

                /* Check real path exists */
                if( empty( $realPath ))
                {
                    $this -> getMon() -> add
                    (
                        [ 'warning', 'include-file-not-fond', $filePath ]
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

            /* Build entity card file name */
            $filename = 'cards' . clScatterName( $hash, 3 ) . '.md';

            /*
                Build link
            */
            $result = self::buildLink
            (
                /* Label */
                empty( $aLabel )
                ? $this -> getProperty( $aId, Epl::NAME, $aId, $aVector )
                : $aLabel,

                /* Link */
                $filename,

                $this -> getProperty( $aId, Epl::HINT, '', $aVector ),
                $this -> getProperty( $aId, Epl::HYPERLINK, '', $aVector )
            );

            /*
                Build card
            */
            /* Check entity hash */
            if( !( $this -> cards[ $hash ] ?? false ))
            {
                /* Get card content */
                $content = $this -> getProperty( $aId, Epl::CARD, '', $aVector );
                /* Build content */
                $content = $this -> buildContentExt( $content, 'CHECK_', $aId );
                /* Write content */
                $this -> writeOutputFile( $filename, $content );
                /* Store hash */
                $this -> cards[ $hash ] = true;
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
                    $vector = $parts[ 1 ] ?? '';

                    if( $this -> getEpl() -> isEntity( $entity ))
                    {
                        $resolved = $this -> buildEntityLink
                        (
                            $link,
                            $item[ 'label' ],
                            $vector
                        );
                    }
                    else
                    {
                        /* File */
                        /* Split anchor */
                        $parts = explode( '#', $link, 2 );
                        $file = $parts[ 0 ];
                        $ancor = $parts[ 1 ] ?? '';
                        if( file_exists( $file ))
                        {
                            $resolved = $link;
                            /* File processing */
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
        string $aIdEntity = null
    )
    {
TODO надо дописать метод
если есть сущност собираем все подмены в %% используем их как ключи для запроса
свойств сущности... ну и выкатываем это.
далее в цикле повторяем всую процедуру пока не перкратятся изменения.
так же надо добавить защиту контента ^^^

        /* Build content */
        $result = $this -> buildContent( $aContent, false, false );
        /* Link processing */
        $result = $this -> linkProcessing( $result, $aFile );

        return $result;
    }



    /*
        Build file and return content
    */
    private function buildFile
    (
        string $aFile
    )
    :string
    {
        $content = $this -> getTemplate( $aFile );

        $this -> buildContentExt( $content, $aFile );

        print_r( $content );

        return $content;
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
