<?php if ( ! defined( 'ABSPATH' ) ) { die; } // Cannot access pages directly.
/**
 *
 * Framework Class
 *
 * @since 1.0.0
 * @version 1.0.0
 *
 */
class CSFramework extends CSFramework_Abstract {

  /**
   *
   * option database/data name
   * @access public
   * @var string
   *
   */
  public $unique = CS_OPTION;

  /**
   *
   * options tab
   * @access public
   * @var array
   *
   */
  public $options = array();

  /**
   *
   * options section
   * @access public
   * @var array
   *
   */
  public $sections = array();

  /**
   *
   * options store
   * @access public
   * @var array
   *
   */
  public $get_option = array();

  /**
   *
   * instance
   * @access private
   * @var class
   *
   */
  private static $instance = null;

  // run framework construct
  public function __construct( $settings, $options ) {

    $this->settings = apply_filters( 'cs_framework_settings', $settings );
    $this->options  = apply_filters( 'cs_framework_options', $options );

    if( ! empty( $this->options ) ) {

      $this->sections   = $this->get_sections();
      $this->get_option = get_option( CS_OPTION );
      $this->addAction( 'admin_init', 'settings_api' );
      $this->addAction( 'admin_menu', 'admin_menu' );
      $this->addAction( 'wp_ajax_cs-export-options', 'export' );

    }

  }

  // instance
  public static function instance( $settings = array(), $options = array() ) {
    if ( is_null( self::$instance ) && CS_ACTIVE_FRAMEWORK ) {
      self::$instance = new self( $settings, $options );
    }
    return self::$instance;
  }

  // get sections
  public function get_sections() {

    $sections = array();

    foreach ( $this->options as $key => $value ) {

      if( isset( $value['sections'] ) ) {

        foreach ( $value['sections'] as $section ) {

          if( isset( $section['fields'] ) ) {
            $sections[] = $section;
          }

        }

      } else {

        if( isset( $value['fields'] ) ) {
          $sections[] = $value;
        }

      }

    }

    return $sections;

  }

  // wp settings api
  public function settings_api() {

    $defaults = array();

    foreach( $this->sections as $section ) {

      register_setting( $this->unique .'_group', $this->unique, array( &$this,'validate_save' ) );

      if( isset( $section['fields'] ) ) {

        add_settings_section( $section['name'] .'_section', $section['title'], '', $section['name'] .'_section_group' );

        foreach( $section['fields'] as $field_key => $field ) {

          add_settings_field( $field_key .'_field', '', array( &$this, 'field_callback' ), $section['name'] .'_section_group', $section['name'] .'_section', $field );

          // set default option if isset
          if( isset( $field['default'] ) ) {
            $defaults[$field['id']] = $field['default'];
            if( ! empty( $this->get_option ) && ! isset( $this->get_option[$field['id']] ) ) {
              $this->get_option[$field['id']] = $field['default'];
            }
          }

        }
      }

    }

    // set default variable if empty options and not empty defaults
    if( empty( $this->get_option )  && ! empty( $defaults ) ) {
      update_option( $this->unique, $defaults );
      $this->get_option = $defaults;
    }

  }

  // section fields validate in save
  public function validate_save( $request ) {

    // ignore nonce requests
    if( isset( $request['_nonce'] ) ) { unset( $request['_nonce'] ); }

    // set section id
    if( isset( $_POST['cs_section_id'] ) ) {
      $transient_time = ( cs_language_defaults() ) ? 30 : 5;
      set_transient( 'cs_section_id', $_POST['cs_section_id'], $transient_time );
    }

    // import
    if ( isset( $request['import'] ) && ! empty( $request['import'] ) ) {
      $decode_string = cs_decode_string( $request['import'] );
      if( is_array( $decode_string ) ) {
        return $decode_string;
      }
      $this->add_settings_error( 'Success. Imported backup options.', 'updated' );
    }

    // reset all options
    if ( isset( $request['resetall'] ) ) {
      $this->add_settings_error( 'Default options restored.', 'updated' );
      return;
    }

    // reset only section
    if ( isset( $request['reset'] ) && isset( $_POST['cs_section_id'] ) ) {
      foreach ( $this->sections as $value ) {
        if( $value['name'] == $_POST['cs_section_id'] ) {
          foreach ( $value['fields'] as $field ) {
            if( isset( $field['id'] ) ) {
              if( isset( $field['default'] ) ) {
                $request[$field['id']] = $field['default'];
              } else {
                unset( $request[$field['id']] );
              }
            }
          }
        }
      }
      $this->add_settings_error( 'Default options restored for only this section.', 'updated' );
    }

    // option sanitize and validate
    foreach( $this->sections as $section ) {
      if( isset( $section['fields'] ) ) {
        foreach( $section['fields'] as $field ) {

          // ignore santize and validate if element multilangual
          if ( isset( $field['type'] ) && ! isset( $field['multilang'] ) && isset( $field['id'] ) ) {

            // sanitize options
            $request_value = isset( $request[$field['id']] ) ? $request[$field['id']] : '';
            $sanitize_type = $field['type'];

            if( isset( $field['sanitize'] ) ) {
              $sanitize_type = ( $field['sanitize'] !== false ) ? $field['sanitize'] : false;
            }

            if( $sanitize_type !== false && has_filter( 'cs_sanitize_'. $sanitize_type ) ) {
              $request[$field['id']] = apply_filters( 'cs_sanitize_' . $sanitize_type, $request_value, $field, $section['fields'] );
            }

            // validate options
            if ( isset( $field['validate'] ) && has_filter( 'cs_validate_'. $field['validate'] ) ) {

              $validate = apply_filters( 'cs_validate_' . $field['validate'], $request_value, $field, $section['fields'] );

              if( ! empty( $validate ) ) {
                $this->add_settings_error( $validate, 'error', $field['id'] );
                $request[$field['id']] = ( isset( $this->get_option[$field['id']] ) ) ? $this->get_option[$field['id']] : '';
              }

            }

          }

          if( ! isset( $field['id'] ) || empty( $request[$field['id']] ) ) {
            continue;
          }

        }
      }
    }

    $request = apply_filters( 'cs_validate_save', $request );

    return $request;
  }

  // field callback classes
  public function field_callback( $field ) {
    $value = ( isset( $field['id'] ) && isset( $this->get_option[$field['id']] ) ) ? $this->get_option[$field['id']] : '';
    echo cs_add_element( $field, $value, $this->unique );
  }

  // settings sections
  public function do_settings_sections( $page ) {

    global $wp_settings_sections, $wp_settings_fields;

    if ( ! isset( $wp_settings_sections[$page] ) ){
      return;
    }

    foreach ( $wp_settings_sections[$page] as $section ) {

      if ( $section['callback'] ){
        call_user_func( $section['callback'], $section );
      }

      if ( ! isset( $wp_settings_fields ) || !isset( $wp_settings_fields[$page] ) || !isset( $wp_settings_fields[$page][$section['id']] ) ){
        continue;
      }

      $this->do_settings_fields( $page, $section['id'] );

    }

  }

  // settings fields
  public function do_settings_fields( $page, $section ) {

    global $wp_settings_fields;

    if ( ! isset( $wp_settings_fields[$page][$section] ) ) {
      return;
    }

    foreach ( $wp_settings_fields[$page][$section] as $field ) {
      call_user_func($field['callback'], $field['args']);
    }

  }

  public function add_settings_error( $message, $type = 'error', $id = 'global' ) {
    add_settings_error( 'cs-framework-errors', $id, $message, $type );
  }

  // adding option page
  public function admin_menu() {

    $defaults           = array(
      'menu_title'      => '',
      'menu_type'       => '',
      'menu_slug'       => '',
      'menu_icon'       => '',
      'menu_capability' => 'manage_options',
      'menu_position'   => null,
    );

    $args = wp_parse_args( $this->settings, $defaults );

    call_user_func( $args['menu_type'], $args['menu_title'], $args['menu_title'], $args['menu_capability'], $args['menu_slug'], array( &$this, 'admin_page' ), $args['menu_icon'], $args['menu_position'] );

  }

  // option page html output
  public function admin_page() {

    $has_nav    = ( count( $this->options ) <= 1 ) ? ' cs-show-all' : '';
    $section_id = ( get_transient( 'cs_section_id' ) ) ? get_transient( 'cs_section_id' ) : $this->sections[0]['name'];
    $section_id = ( isset( $_GET['cs-section'] ) ) ? esc_attr( $_GET['cs-section'] ) : $section_id;

    echo '<div class="cs-framework cs-option-framework">';

      echo '<form method="post" action="options.php" enctype="multipart/form-data" id="csframework_form">';
      echo '<input type="hidden" class="cs-reset" name="cs_section_id" value="'. $section_id .'" />';

      if( $this->settings['ajax_save'] !== true ) {
        settings_errors();
      }

      settings_fields( $this->unique. '_group' );

      echo '<header class="cs-header">';
      echo '<h1>Codestar Framework <small>by Codestar</small></h1>';
      echo '<fieldset>';
      echo ( $this->settings['ajax_save'] === true ) ? '<span id="cs-save-ajax">'. __( 'Settings saved.', CS_TEXTDOMAIN ) .'</span>' : '';
      submit_button( __( 'Save', CS_TEXTDOMAIN ), 'primary', 'save', false, array( 'data-ajax' => $this->settings['ajax_save'], 'data-save' => __( 'Saving...', CS_TEXTDOMAIN ) ) );
      submit_button( __( 'Restore', CS_TEXTDOMAIN ), 'secondary cs-restore cs-reset-confirm', $this->unique .'[reset]', false );
      echo '</fieldset>';
      echo ( empty( $has_nav ) ) ? '<a href="#" class="cs-expand-all"><i class="fa fa-eye-slash"></i> '. __( 'show all options', CS_TEXTDOMAIN ) .'</a>' : '';
      echo '<div class="clear"></div>';
      echo '</header>'; // end .cs-header

      echo '<div class="cs-body'. $has_nav .'">';

        echo '<div class="cs-nav">';

          echo '<ul>';
          foreach ( $this->options as $key => $tab ) {

            if( ( isset( $tab['sections'] ) ) ) {

              $tab_active   = cs_array_search( $tab['sections'], 'name', $section_id );
              $active_style = ( ! empty( $tab_active ) ) ? ' style="display: block;"' : '';
              $active_list  = ( ! empty( $tab_active ) ) ? ' cs-tab-active' : '';
              $tab_icon     = ( ! empty( $tab['icon'] ) ) ? '<i class="cs-icon '. $tab['icon'] .'"></i>' : '';

              echo '<li class="cs-sub'. $active_list .'">';

                echo '<a href="#" class="cs-arrow">'. $tab_icon . $tab['title'] .'</a>';

                echo '<ul'. $active_style .'>';
                foreach ( $tab['sections'] as $tab_section ) {

                  $active_tab = ( $section_id == $tab_section['name'] ) ? ' class="cs-section-active"' : '';
                  $icon = ( ! empty( $tab_section['icon'] ) ) ? '<i class="cs-icon '. $tab_section['icon'] .'"></i>' : '';

                  echo '<li><a href="#"'. $active_tab .' data-section="'. $tab_section['name'] .'">'. $icon . $tab_section['title'] .'</a></li>';

                }
                echo '</ul>';

              echo '</li>';

            } else {

              $icon = ( ! empty( $tab['icon'] ) ) ? '<i class="cs-icon '. $tab['icon'] .'"></i>' : '';

              if( isset( $tab['fields'] ) ) {

                $active_list = ( $section_id == $tab['name'] ) ? ' class="cs-section-active"' : '';
                echo '<li><a href="#"'. $active_list .' data-section="'. $tab['name'] .'">'. $icon . $tab['title'] .'</a></li>';

              } else {

                echo '<li><div class="cs-seperator">'. $icon . $tab['title'] .'</div></li>';

              }

            }

          }
          echo '</ul>';

        echo '</div>'; // end .cs-nav

        echo '<div class="cs-content">';

          echo '<div class="cs-sections">';

          foreach( $this->sections as $section ) {

            if( isset( $section['fields'] ) ) {

              $active_content = ( $section_id == $section['name'] ) ? ' style="display: block;"' : '';
              echo '<div id="cs-tab-'. $section['name'] .'" class="cs-section"'. $active_content .'>';
              echo ( isset( $section['title'] ) && empty( $has_nav ) ) ? '<div class="cs-section-title"><h3>'. $section['title'] .'</h3></div>' : '';
              $this->do_settings_sections( $section['name'] . '_section_group' );
              echo '</div>';

            }

          }

          echo '</div>'; // end .cs-sections

          echo '<div class="clear"></div>';

        echo '</div>'; // end .cs-content

        echo '<div class="cs-nav-background"></div>';

      echo '</div>'; // end .cs-body

      echo '<footer class="cs-footer">';
      echo 'Codestar Framework <strong>v('. CS_VERSION .') by Codestar</strong>';
      echo '</footer>'; // end .cs-footer

      echo '</form>'; // end form

      echo '<div class="clear"></div>';

    echo '</div>'; // end .cs-framework

  }

  // export options
  public function export() {

    header('Content-Type: plain/text');
    header('Content-disposition: attachment; filename=backup-options-'. gmdate( 'd-m-Y' ) .'.txt');
    header('Content-Transfer-Encoding: binary');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo cs_encode_string( get_option( CS_OPTION ) );

    die();

  }

}
