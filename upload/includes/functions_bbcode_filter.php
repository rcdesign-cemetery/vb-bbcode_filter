<?php
/**
 * Check, is mod enabled for this user in this section of forum
 *
 * @global vB_Registry $vbulletin
 * @param array $permissions
 * @param mixed $forumid
 * @param string $optiongroup
 * @param bool $is_filter_type_inbound - Indicates whether the filtering is for presaving or for output rendering.
 * @return bool
 */
function is_need_aditional_bbtags_verification($permissions, $forumid = NULL, $optiongroup = 'allowedbbcodes', $is_filter_type_inbound = true)
{
    global $vbulletin;

    // form the name of the option, responsible for enabling additional filters
    $filtered_section = NULL;
    if ('allowedbbcodes' == $optiongroup)
    {
        if (is_null($forumid) AND 'private' == THIS_SCRIPT)
        {
            // Cancel filtering private messages for output rendering
            // That conflicted with other mods. Output filter makes sense only for old forum posts
            if (!$is_filter_type_inbound)
            {
                return false;
            }
            $forumid = 'privatemessage';
        }
        
        if (intval($forumid) || 'privatemessage' == $forumid)
        {
            $filtered_section = 'enable_bbcode_filter_for_forum';
        }
    }
    else
    {
        $filtered_section = 'enable_bbcode_filter_for_' . substr($optiongroup, 0, 2);
    }

    $aditional_bbcode_filters = unserialize($permissions['aditional_bbcode_filters']);

    // get mod settings
    $is_need_aditional_check = false;
    if (!is_null($filtered_section) AND isset($vbulletin->options[$filtered_section])
        AND is_array($aditional_bbcode_filters))
    {
            $is_need_aditional_check = ($vbulletin->options[$filtered_section] AND $permissions['bbcode_filter_on']); 
    }
    return $is_need_aditional_check;
}

/**
 * Get an array of bbtags stusus(allow / forbid)  for user
 *
 *
 * @global vB_Registry $vbulletin
 * @param array $user_info
 * @param mixed $forumid
 * @param string $optiongroup
 * @param bool $is_filter_type_inbound - Indicates whether the filtering is for presaving or for output rendering.
 * @return array
 * 
 */
function get_tags_status_list($user_info = NULL, $forumid = NULL, $optiongroup = NULL, $is_filter_type_inbound = true)
{
    global $vbulletin;
    $allawbbcodes = array(
        'BASIC',
        'COLOR',
        'SIZE',
        'FONT',
        'ALIGN',
        'LIST',
        'URL',
        'CODE',
        'PHP',
        'HTML',

        // IMG tag permissions are crazy. It's better to leave intact.
        // Usually, no needs to disable it at all. Less work - less problems :)
        //'IMG'

        // Skip qustom and quote tags, to simplify processing.
        // Usually, no needs to disable those at all.
        //'QUOTE',
        //'CUSTOM',
    );
    // user init
    if (is_null($user_info) || empty($user_info))
    {
        $user_info = $vbulletin->userinfo;
    }
    $userid = $user_info['userid'];

    // optiongroup init
    if (is_null($optiongroup))
    {
        switch (THIS_SCRIPT)
        {
            case 'group':
                $optiongroup = 'sg_allowed_bbcode';
                break;
            case 'visitormessage':
                $optiongroup = 'vm_allowed_bbcode';
                break;
            case 'picturecomment':
                $optiongroup = 'pc_allowed_bbcode';
                break;
            default:
                $optiongroup = 'allowedbbcodes';
        }
    }

    $permissions = fetch_permissions(0, $userid, $user_info);
    $aditional_bbcode_filters = unserialize($permissions['aditional_bbcode_filters']);
    $is_need_aditional_verification = is_need_aditional_bbtags_verification($permissions, $forumid, $optiongroup, $is_filter_type_inbound);
    // checking tags
    $tags = array();
    foreach ($allawbbcodes as $bbtag)
    {
        $tag_bit = @constant('ALLOW_BBCODE_' . strtoupper($bbtag));
        if ($is_need_aditional_verification AND array_key_exists($tag_bit, $aditional_bbcode_filters))
        {
            $tags[$bbtag] = $aditional_bbcode_filters[$tag_bit] ? $tag_bit : 0;
        }
        else
        {
            $tags[$bbtag] = $vbulletin->options[$optiongroup] & $tag_bit;
        }
    }
    return $tags;
}

/**
 * Removes $bbtag from the text, without regular expressions
 *
 * @param string $text
 * @param string $bbtag
 * @return string
 */
function simple_bbtag_replace($text, $bbtag)
{
    $length_before = strlen($text);
    $text = str_ireplace('['. $bbtag .']', '', $text);
    if (strlen($text) != $length_before )
    {
        $text = str_ireplace('[/'. $bbtag .']', '', $text);
    }
    return $text;
}

/**
 * Removes $bbtag from the text, used regular expressions
 *
 * @param string $text
 * @param string $bbtag
 * @param string $pattern
 * @return string
 */
function preg_bbtag_replace($text, $bbtag, $pattern  = NULL)
{
    if (is_null($pattern))
    {
        $pattern = '#(\[' . $bbtag .'(=?.*?)\])#i';
    }
    $length_before = strlen($text);
    $text = preg_replace($pattern, '', $text);
    if (strlen($text) != $length_before )
    {
        $text = str_ireplace('[/'. $bbtag .']', '', $text);
    }
    return $text;
}

/**
 * Remove from text all disabled bbtags
 *
 * @param string $text
 * @param array $user_info
 * @param mixed $forumid
 * @param string $optiongroup
 * @param bool $is_filter_type_inbound - Indicates whether the filtering is for presaving or for output rendering.
 * @return string
 */
function remove_disabled_bbtags($text, $user_info = NULL, $forumid = NULL, $optiongroup = NULL, $is_filter_type_inbound = true)
{
    $tag_delete_rules = array(
        'BASIC' => array(
            'clear_type'=>'simple',
            'tags'=>array('B', 'I', 'U'),
        ),
        'COLOR' => array(
            'clear_type'=>'preg',
        ),
        'SIZE' => array(
            'clear_type'=>'preg',
        ),
        'FONT' => array(
            'clear_type'=>'preg',
        ),
        'ALIGN' => array(
            'clear_type'=>'simple',
            'tags'=>array('LEFT', 'CENTER', 'RIGHT')
        ),
        'LIST' => array(
            'clear_type'=>'preg',
        ),
        'URL' => array(
            'clear_type'=>'preg',
        ),
        'CODE' => array(
            'clear_type'=>'simple',
        ),
        'PHP' => array(
            'clear_type'=>'simple',
        ),
        'HTML' => array(
            'clear_type'=>'simple',
        ),

        // IMG tag permissions are crazy. It's better to leave intact.
        // Usually, no needs to disable it at all. Less work - less problems :)
        //'IMG',

        // Skip qustom and quote tags, to simplify processing.
        // Usually, no needs to disable those at all.
        //'QUOTE',
        //'CUSTOM',

    );
    $tags = get_tags_status_list($user_info, $forumid, $optiongroup, $is_filter_type_inbound);

    foreach ($tags as $tag_group=>$is_tag_enable)
    {

        if (!$is_tag_enable)
        {
            $tag_group_info = $tag_delete_rules[$tag_group];
            if (is_array($tag_group_info))
            {
                if (!array_key_exists('tags' , $tag_group_info))
                {
                    $tag_group_info['tags'] = array($tag_group);
                }

                foreach ($tag_group_info['tags'] as $bbtag)
                {
                    switch ($tag_group_info['clear_type'])
                    {
                        case 'preg':
                            $text = preg_bbtag_replace($text, $bbtag, $tag_group_info['pattern']);
                            break;
                        case 'simple':
                        default:
                            $text = simple_bbtag_replace($text, $bbtag);
                    }
                }
            }
        }
    }
    /**
     * tag INDENT can be allow for to bbcode options ALIGN and LIST
     * remove only if both group are desabled
     */
    if (!($tags['ALIGN'] || $tags['LIST']))
    {
        $text = preg_bbtag_replace($text, 'INDENT', $tag_group_info['pattern']);
    }
    return $text;
}

?>