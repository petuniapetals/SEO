// ---------------------/* support YoastSEO */-------------------------- //
class YoastSeoCustomData {
    constructor() {
        // Ensure YoastSEO.js is present and can access the necessary features.
        if ( typeof YoastSEO === "undefined" || typeof YoastSEO.analysis === "undefined" || typeof YoastSEO.analysis.worker === "undefined" ) {
            return;
        }
        YoastSEO.app.registerPlugin( "YoastSeoCustomData", { status: "ready" } );
        this.registerModifications();
    }

    /**
     * Registers the addContent modification.
     *
     * @returns {void}
     */
    registerModifications() {
        const callback = this.addContent.bind( this );
        // Ensure that the additional data is being seen as a modification to the content.
        YoastSEO.app.registerModification( "content", callback, "YoastSeoCustomData", 10 );
    }

    /**
     * Adds to the content to be analyzed by the analyzer.
     *
     * @param {string} data The current data string.
     *
     * @returns {string} The data string parameter with the added content.
     */
    addContent( data ) {
        data = seoContent ? seoContent : data;
        return data ;
    }
}
/**
 * Adds eventlistener to load the plugin.
 */
if ( typeof YoastSEO !== "undefined" && typeof YoastSEO.app !== "undefined" ) {
    new YoastSeoCustomData();
} else {
    jQuery( window ).on(
        "YoastSEO:ready",
        function() {
            new YoastSeoCustomData();
        }
    );
}

// ---------------------/* support RankMath */-------------------------- //
/**
 * RankMath custom data integration class
 */
class RankMathCustomData {
    /**
     * Class constructor
     */
    constructor() {
        this.init()
        this.hooks()
    }

    /**
     * Init the custom fields
     */
    init() {
        this.getContent = this.getContent.bind( this )
    }

    /**
     * Hook into Rank Math App eco-system
     */
    hooks() {
        if (typeof wp !== 'undefined' && wp.hooks !== undefined) {
            wp.hooks.addFilter( 'rank_math_content', 'rank-math', this.getContent, 11 )
        }
    }

    /**
     * Replace page content to publish nicepage html for analysis
     *
     * @param {string} content original page content
     *
     * @return {string} Replaced nicepage content.
     */
    getContent ( content ) {
        content = seoContent ? seoContent : content;
        return content
    }
}

jQuery( function() {
    setTimeout( function() {
        new RankMathCustomData()
    }, 500 )
} )
