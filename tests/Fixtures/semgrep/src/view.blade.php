<div>{{ $title }}</div>
{{-- ruleid: sec-04 --}}
<div>{!! $html !!}</div>
<div>{!! $block !!}</div> {{-- nosemgrep -- rendered by the block, already escaped --}}
{{-- Escaped by construction: not reported --}}
<div>{!! $slot !!}</div>
<a href="{!! esc_url( $url ) !!}" class="{!! esc_attr( $class ) !!}">{!! esc_html__( 'Read', 'x' ) !!} {!! wp_kses_post( $summary ) !!}</a>
<article {!! post_class() !!}>{!! \wc_price( $total ) !!}</article>
<div {!! get_block_wrapper_attributes(['class' => 'is-x']) !!}>{!! get_previous_posts_link('&larr;') !!}{!! get_next_posts_link('&rarr;') !!}</div>
{!! wp_nav_menu([
    'theme_location' => 'primary',
]) !!}
{{-- ruleid: sec-04 --}}
<div>{!! apply_filters( 'the_content', $text ) !!}</div>
{{-- ruleid: sec-04 --}}
<div>{!! get_option( 'store_notice' ) !!}</div>
{{-- ruleid: sec-04 --}}
<div>{!! do_shortcode( '[products]' ) !!}</div>
{{-- ruleid: sec-04 --}}
<div>{!! $slotted !!}</div>
{{-- ruleid: sec-04 --}}
<div>{!! sprintf( '%s', $name ) !!}</div>
{{-- ruleid: sec-04 --}}
<div>{!! my_theme_helper() !!}</div>
