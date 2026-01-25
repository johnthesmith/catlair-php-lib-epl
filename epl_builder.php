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
    private ?string $destinationSubpath = null;

    /* Source path */
    private ?string $source = null;

    /* Result metamodel path */
    private string $metamodel = 'file.yaml';

    /* Result debug file */
    private string $debug = 'debug.yaml';



    /*
        Processing queue for new docs
            type: entity/doc/ext
            target: Entity id/file name
            vector: vector for entity
            label: label for link
    */
    private array $queue = [];



    /*
        All documents and entity cache key:value
            key: document hash
            value: true/false
    */
    private array $docs = [];



    /*
        All links cache key:value
            key: link hash
            value:
                content: link conntent,
                success: true/false - information abount mislink
    */
    private array $links = [];



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
            Сборка модели из YAML/JSON.
            Старт с index.md → задача file в $queue.
            Цикл пока $queue не пуст:
                Берём задачу из $queue.
                Если файл → читаем, находим ссылки → для каждой:
                    Валидируем (сущность/файл существует).
                    Строим ссылку (готовый Markdown).
                    Сохраняем в $links[hash] (кеш).
                    Ставим задачу в $queue (если ещё нет).
                    Вставляем ссылку в контент.
                    Записываем файл.
                Если сущность → генерируем карточку → записываем.
            Конец. Все зависимости обработаны, ссылки закешированы, файлы созданы.
    */
    public function run
    (
        /* Source index file from string */
        string $aIndexFile
    )
    :self
    {
        $this
        -> getLog()
        -> begin( 'Start processing' );

        /* Start monitoring */
        $this
        -> getMon()
        -> now([ 'stat', 'begin' ]);

        /* Build epl */
        $this -> getEpl()
        -> assemble( $this -> source )
        -> resultTo( $this );

        /* Let link index */
        $this -> queue = [];
        /* Processed links */
        $this -> links = [];

        /* Start point */
        $this -> addFileLink( $aIndexFile );

        /* Processing */
        while( !empty( $this -> queue ))
        {
            $this -> getLog() -> begin( 'Queue loop' );

            /* Get first element */
            $hash = array_key_first( $this -> queue );
            $task = $this -> queue[ $hash ];

            $this -> getLog() -> dump( $task, 'Task' );

            unset( $this -> queue[ $hash ]);
            switch( $task[ 'type' ])
            {
                case "file":
                    $link = $this -> processFileLink( $task );
                break;
                case 'entity':
                    $link = $this -> processEntityLink( $task );
                break;
                default:
                break;
            }
            $this -> links[ $hash ] = $link;

            $this -> getLog() -> end();
        }

        /* Dump monitor */
        $debugFile = $this -> buildDestination
        (
            '/' . $this -> destinationSubpath . '/'. $this -> debug
        );
        $this -> getMon() -> drop( $debugFile ) -> flush( $debugFile );

        $this -> getLog() -> end();

        return $this;
    }




    /**************************************************************************
        Link processing
        Methods from this section are process specific types of links.
            entity
            file
            external
            unknown
    */



    /*
        Processing file link
        Build new file
    */
    private function processFileLink
    (
        $aTask
    )
    :self
    {
        /* Extract file name */
        $file = $aTask[ 'target' ];
        $reason = $aTask[ 'reason' ] ?? '';
        $vector = $aTask[ 'vector' ] ?? [];

        /* Split anchor */
        $parts = explode( '#', $file, 2 );
        $fileName = $parts[ 0 ];
        $ancor = $parts[ 1 ] ?? '';

        /* Build real normalized path in FS */
        $sourceFile = $this -> getSource( $fileName );

        /* Build real normalized destination in FS */
        $destinationFile = $this -> buildDestination
        (
            '/' . $this -> destinationSubpath . '/' .
            $fileName
        );

        /* Call file processing */
        $content = $this -> getTemplate( $sourceFile );

        if( !empty( $content ))
        {
            /* Get file extension */
            $ext = strtolower( pathinfo( $fileName, PATHINFO_EXTENSION ));

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
                        $fileName,
                        null,
                        $vector
                    );
                    break;
                default: break;
            }

            /* Write content */
            $this -> writeOutput( $destinationFile, $content );
        }
        return $this;
    }



    private function processEntityLink
    (
        $aTask
    )
    :self
    {
        /* Extract file name */
        $entity = $aTask[ 'target' ];
        $reason = $aTask[ 'reason' ] ?? '';
        $vector = $aTask[ 'vector' ] ?? [];

        if( $this -> getEpl() -> isEntity( $entity ) )
        {
            /* Build entity card hash */
            $fileName = $this -> entityToCardPath( $entity, $vector );
            /* Build real normalized destination in FS */
            $destinationFile = $this -> buildDestination( $fileName );
            /* Get card content */
            $content = $this -> getProperty
            (
                $entity,
                [ self::ENTITY_CARD ],
                '',
                $vector
            );

            /* Build content */
            $content = $this -> buildContentExt
            (
                $content,
                $fileName,
                $entity,
                $vector
            );

            /* Write content */
            $this -> writeOutput( $destinationFile, $content );
        }
        return $this;
    }



    /**************************************************************************
        Link extracotr section

        `linkExtractor` search and replace all links in content
        Each link will be add in to queue for processing by loop of `run`
        method.
            links
            files
    */


    /*
        Extract all [label](link "hint") from content
    */
    private function linkExtractor
    (
        /* Content for processing */
        string $content,
        /* Processing file name for relative links */
        string $aReasonFile,
        /* Default vector for files porcessing */
        string|array $aVector = null
    )
    :string
    {
        $links = [];

        /* Link pattern */
        $pattern = '/\[(.*?)\]\((.*?)\)/';

        /* find all atches */
        preg_match_all
        (
            $pattern,
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        /* Build array of links */
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
            /* Build link hash */
            $linkHash = self::buildLinkHash( $item[ 'full' ]);
            $linkRaw = $item[ 'link' ];
            $label = $item[ 'label' ];
            /* Link and hint */
            preg_match( '/^(.*?)\s*"(.*)"$/', $linkRaw, $matches);
            $link = $matches[1] ?? $linkRaw;
            $hint = $matches[2] ?? '';

            $linkContent = null;
            $success = true;

            /* Looking link in the link cache by link hash */
            if( isset( $this -> links[ $linkHash ]))
            {
                /*  Return item from links */
                $l = $this -> links[ $linkHash ];
                $linkContent = $l[ 'content' ];
                $success = $l[ 'success' ];
            }
            else
            {
                /* External link */
                /* Protocol check (external URL) */
                if( preg_match( '#^(https?|ftp|mailto)://#', $link ))
                {
                    /* External linkk */
                    $linkContent = self::buildLink( $label, $link );
                }
                else
                {
                    /* Anchor */
                    /* Local anchor link */
                    if( strpos( $link, '#' ) === 0 )
                    {
                        $linkContent = $item[ 'full' ];
                    }
                    else
                    {
                        /* Entity [](entity) */
                        /* Convert link string to entity reference */
                        $ref = Epl::parsePropertyRef( $link );
                        $linkContent = $this -> addEntityLink
                        (
                            $ref[ 'entity' ],
                            $ref[ 'vector' ],
                            $item[ 'label' ],
                            $hint,
                            $aReasonFile
                        );

                        /* File [](file) */
                        if( empty( $linkContent ))
                        {
                            /* Split anchor */
                            $parts = explode( '#', $link, 2 );
                            $file = $parts[ 0 ];
                            $ancor = $parts[ 1 ] ?? '';
                            /* Resolve link file relative file */
                            $file = $this -> resolveToProjectPath
                            (
                                $file,
                                $aReasonFile,
                            );
                            /* Check source file */
                            $linkContent  = $this -> addFileLink
                            (
                                $file,
                                $label,
                                $hint,
                                $aReasonFile
                            );

                            if( empty( $linkContent ))
                            {
                                /* Unknown link */
                                $linkContent = '`unknown-link:' . $link . '`';
                                $success = false;
                            }
                        }
                    }
                }

                /* Store link in to the link cache */
                $this -> links[ $linkHash ] =
                [
                    'content' => $linkContent,
                    'success' => $success
                ];
            }

            if( !$success )
            {
                $this -> getMon() -> add
                (
                    [ 'warning', 'unknown-link', $link, $aReasonFile ]
                );
            }

            /* Store link */
            $processedLinks[] =
            [
                'content'   => $linkContent,
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
        Add new file for processing in to the queue
            Проверяет существование файла,
            строит ссылку,
            добавляет задачу в $queue,
            возвращает ссылку.
    */
    private function addFileLink
    (
        /* File for link, relative path */
        string $aLinkFile,
        /* Label for link */
        string $aLabel = '',
        /* Hint for the link */
        string $aHint = '',
        /* Reason file with link */
        ?string $aReasonFile = ''
    )
    /*
        content link
        success file exists
    */
    :?string
    {
        /* Final result */
        $result = null;

        $reasonDir = dirname( $aReasonFile );

        /* Retrive base path from root */
        $baseFile = clNormalizePath
        (
            (empty( $reasonDir ) ? '' : ( $reasonDir . '/' )). $aLinkFile
        );

        /* Retrive source path */
        $sourceFile = $this -> getSource( $baseFile );

        /* Check absolute path file exists  */
        if( file_exists( $sourceFile ))
        {
            /* Build link */
            $result = self::buildLink
            (
                $aLabel,
                $aLinkFile,
                $aHint,
                $this -> getProperty
                (
                    'archcode-build',
                    [ self::ENTITY_HYPERLINK],
                    ''
                )
            );

            /* Build document hash */
            $docHash = $this -> buildHash
            ([
                'type' => 'file',
                'target' => $baseFile
            ]);

            /* Check document in the cache by hash */
            if( !isset( $this -> docs[ $docHash ]))
            {
                /* Add docs to cache */
                $this -> docs[ $docHash ] = true;
                /* Add queue task */
                $this -> queue[] =
                [
                    'type'    => 'file',
                    'target'  => $baseFile,
                    'vector'  => [],
                    'reason'  => $aReasonFile
                ];
            }
        }

        return $result;
    }



    /*
        Cretate or find entity link
    */
    private function addEntityLink
    (
        /* Arguments after parsePropertyRef */
        string $aEntityId,
        /* Link vector */
        array $aVector = [],
        /* Link label */
        ?string $aLabel = null,
        /* Link hint */
        ?string $aHint = null,
        /* Reason file with link */
        ?string $aReasonFile = null
    )
    :?string
    {
        /* Final result */
        $result = null;

        /* Check link hash exists */
        if( $this -> getEpl() -> isEntity( $aEntityId ))
        {
            /* Build link */
            $result = self::buildLink
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
                $this -> entityToCardPath( $aEntityId, $aVector ),
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

            /* Build document hash */
            $docHash = $this -> buildHash
            ([
                'type' => 'entity',
                'target' => $aEntityId,
                'vector' => $aVector
            ]);

            if( !isset( $this -> docs[ $docHash ]))
            {
                /* Add docs to cache */
                $this -> docs[ $docHash ] = true;
                /* Add queue task */
                $this -> queue[] =
                [
                    'type'    => 'entity',
                    'target'  => $aEntityId,
                    'vector'  => $aVector,
                    'reason'  => $aReasonFile
                ];
            }
        }

        return $result;
    }




    /***************************************************************************
    */

    /*
        Build content
        Recive content, build main, link processing
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

        /* Extract alinks from content */
        $result = $this -> linkExtractor( $result, $aFile, $aVector );

        /* Build result */
        return $result;
    }



    /*
        Return formated link content
    */
    static private function buildLink
    (
        string $aLabel,
        string $aLink,
        string $aHint = '',
        string $aTemplate = '[%label%](%link% %hint%)'
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




    /**************************************************************************
        Utils
    */




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
            $result = $this -> getSource( $aValue );
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
        $currentPath = dirname( $aCurrent );
        if
        (
            !empty( $currentPath ) &&
            (
                strpos( $aLink, './' ) === 0 ||
                strpos( $aLink, '../' ) === 0
            )
        )
        {
            $result = $currentPath . '/' . $aLink;
        }
        else
        {
            $result = $aLink;
        }
        return clNormalizePath( $result );
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
        Build link hash
    */
    private static function buildLinkHash
    (
        /* Link []() */
        string $aLink
    )
    {
        /* Build link hash */
        return hash( 'sha256', $aLink );
    }



    /*
        Build entity card file in the project
    */
    private function entityToCardPath
    (
        /* Entity id */
        string $aId,
        /* Vector array */
        array $aVector
    )
    :string
    {
        return clNormalizePath
        (
            implode
            (
                '',
                [
                    '/',
                    $this -> destinationSubpath,
                    '/entities/',
                    $aId,
                    '/',
                    Epl::vectorToString( $aVector ),
                    '.md'
                ]
            )
        );
    }



    /*
        build hash from string or array argument
    */
    private static function buildHash
    (
        /* Argument for hash */
        string | array $a
    )
    :string
    {
        if( is_array( $a ))
        {
            $a = serialize( $a );
        }
        return hash( 'sha256', $a );
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
        $path = $aInternalPath;

print_r($path . PHP_EOL );

        $dir = dirname( $path );

        if( clCheckPath( $dir ))
        {
            /* Store file */
            $result = file_put_contents( $path, $aContent );
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
        Return destination with relative path
    */
    public function buildDestination
    (
        /* Relative path */
        string $aLocal = null
    )
    {
        $path = [ $this -> destination ];

        if( !empty( $aLocal ))
        {
            $path[] = $aLocal;
        }

        return clNormalizePath( implode( '/', $path ));
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
    public function setDestinationSubpath
    (
        /* Dst path */
        $a
    )
    :self
    {
        $this -> destinationSubpath = $a;
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


    /*
        Return source with relative path
    */
    public function getSource
    (
        /* Relative path */
        string $a = null
    )
    {
        return clNormalizePath
        (
            $this -> source . ( $a === null ? '' : ( '/' . $a ))
        );
    }
}
