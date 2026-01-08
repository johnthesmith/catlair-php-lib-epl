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

    /* Result metamodel path */
    private string $metamodel = 'file.yaml';

    /* Result debug file */
    private string $debug = 'debug.yaml';



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
        /* Source files */
        string $aSourceEpl,
        /* Source index file from string */
        string $aIndexFile
    )
    :self
    {
        $this
        -> getMon()
        -> now([ 'stat', 'begin' ]);

        /* Build epl */
        $this -> getEpl()
        /* Assemble epl model from epl path */
        -> assemble( $aSourceEpl )
        -> resultTo( $this )
        ;

        $this -> buildFile( $aIndexFile );


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
        Return link in selected format
    */
    static private function buildLink
    (
        string $aLabel,
        string $aLink,
        string $aHint = ''
    )
    :string
    {
        return implode
        (
            '',
            [
                '[',
                $aLabel,
                ']',
                '(',
                $aLink,
                empty( $aHint ) ? '' : ( '|'. $aHint ),
                ')'
            ]
        );
    }



    /*
        Build entity link and return it
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
        /* Description */
            $hint = $this -> getEpl() -> getProperty
            (
                $aId,
                Epl::HINT,
                '',
                $aVector
            );

            $result = self::buildLink
            (

                /* Label */
                empty( $aLabel )
                ? $this
                -> getEpl()
                -> getProperty
                (
                    $aId,
                    Epl::NAME,
                    $aId,
                    $aVector
                )
                : $aLabel,

                /* Link */
                '/cards/id/' . $aId,

                empty( $hint ) ? '' : '|' . $hint
            );
        }

        return $result;
    }



    /*
        Extract all [label](link)
    */
    private function linkProcessing
    (
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
            $resolved = '';

            /* 1. Protocol check (external URL) */
            if( preg_match( '#^(https?|ftp|mailto)://#', $link ))
            {
                $resolved = $link;
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
                    /* Split vector */
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
                        /* Link processing */
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



    private function buildFile
    (
        string $aFile
    )
    :string
    {
        $content = $this -> getTemplate( $aFile );

        /* Build content */
        $content = $this -> buildContent( $content, false, false );
        /* Link processing */
        $content = $this -> linkProcessing( $content, $aFile );

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
}
