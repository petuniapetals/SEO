//For edit page meta boxes
jQuery(document).ready(function($){
    if (parent.dataBridge) {
        var protectedWithNp = parent.dataBridge.getInfo().protectedWithNp || false;
        var type = protectedWithNp ? 'password' : 'text';
        jQuery('#post_password').attr('type', type);
    }
});