// Mermaid initialization for Material for MkDocs.
// Runs on every navigation so diagrams render after instant-load page swaps.
// securityLevel "loose" allows the HTML labels (<br/>, <b>) used in the diagrams.
document$.subscribe(function () {
    mermaid.initialize({
        startOnLoad: true,
        theme: "default",
        securityLevel: "loose",
    });
});
