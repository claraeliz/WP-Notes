(function(){
  function ready(cb){
    if(document.readyState === "complete") return cb();
    window.addEventListener("load", cb);
  }

  ready(function(){

    
    const wrap = document.getElementById("wpnp3-notes-wrapper");
    if(!wrap) return;

    wrap.style.position = "absolute";
    wrap.style.left = "0";
    wrap.style.top = "0";
    wrap.style.width = "0";
    wrap.style.height = "0";
    wrap.style.pointerEvents = "none";

    const payload = window.wpnp3NotesPayload || [];
    if(!Array.isArray(payload) || !payload.length) return;

    const uid = (window.WPNP3 && Number(WPNP3.uid)) || 0;

    function rect(el){ return el.getBoundingClientRect(); }

   function docFromCenter(cx, cy) {
      const centerX = window.innerWidth / 2;
      const leftDoc = window.scrollX + centerX + cx;
      const topDoc  = cy; // absolute vertical doc coordinate
      return { leftDoc, topDoc };
    }

    function centerFromDoc(leftDoc, topDoc) {
      const centerX = window.innerWidth / 2;
      const cx = (leftDoc - window.scrollX) - centerX;
      const cy = topDoc; // already absolute doc coordinate
      return { cx, cy };
    }

    function savePosition(id,cx,cy){
      if(!window.WPNP3) return;
      const body = new FormData();
      body.append("action","wpnp3_save_position");
      body.append("nonce", WPNP3.nonce);
      body.append("id", id);
      body.append("cx", cx);
      body.append("cy", cy);
      fetch(WPNP3.ajaxUrl,{method:"POST",credentials:"same-origin",body})
        .then(r=>r.json())
        .then(res=>{ if(!res.success) console.error("WPNP3 save failed",res); })
        .catch(()=>{});
    }

    function normalizeToHex(color) {
      if (!color) return "#ffeb3b";
      if (color.startsWith("#")) return color;
      const m = color.match(/\d+/g);
      if (!m) return "#ffeb3b";
      const [r, g, b] = m.map(Number);
      return "#" + [r, g, b].map(x => x.toString(16).padStart(2, "0")).join("");
    }

    function luminance(hex) {
      hex = hex.replace("#", "");
      let r = parseInt(hex.substr(0, 2), 16) / 255;
      let g = parseInt(hex.substr(2, 2), 16) / 255;
      let b = parseInt(hex.substr(4, 2), 16) / 255;
      const toLin = c =>
        c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
      r = toLin(r); g = toLin(g); b = toLin(b);
      return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    }

    payload.forEach(n=>{
        const el = document.createElement("div");
        el.className="wpnp3-sticky-note";
        el.dataset.id=n.id;
        el.dataset.author=n.author;
        el.dataset.color=n.color || "#ffeb3b";

        if(Number.isFinite(n.cx)) el.dataset.cx = n.cx;
        if(Number.isFinite(n.cy)) el.dataset.cy = n.cy;

        el.innerHTML = `<h4>${n.title||""}</h4><div class="wpnp3-content">${n.content||""}</div>`;
        el.style.background = el.dataset.color;

        // Author can drag
        if(uid && Number(el.dataset.author)===uid){
          el.classList.add("wpnp3-draggable");
        }

        // FIXED: use "el" here, not "note"
        const bg = normalizeToHex(
          el.dataset.color || getComputedStyle(el).backgroundColor
        );
        el.style.color = luminance(bg) > 0.5 ? "#000" : "#fff";

        wrap.appendChild(el);
    });


    const notes = Array.from(document.querySelectorAll(".wpnp3-sticky-note"));

    function place(note){
      let cx = parseFloat(note.dataset.cx);
      let cy = parseFloat(note.dataset.cy);

      if(!Number.isFinite(cx)) cx = 0;
      if(!Number.isFinite(cy)) cy = 140;

      const p = docFromCenter(cx, cy);
      note.style.left = p.leftDoc + "px";
      note.style.top  = p.topDoc  + "px";

      note.dataset.cx = cx;
      note.dataset.cy = cy;
    }

    function placeAll(){ notes.forEach(place); }

    placeAll();
    window.addEventListener("resize", ()=>{
      clearTimeout(window.__wpnp3RT);
      window.__wpnp3RT = setTimeout(placeAll, 60);
    });

    let active=null,startX=0,startY=0,startCx=0,startCy=0;

    function onDown(e){
      const n = e.target.closest(".wpnp3-draggable");
      if(!n) return;
      active=n;
      active.setPointerCapture(e.pointerId);

      startX=e.pageX; startY=e.pageY;
      startCx=parseFloat(active.dataset.cx||"0");
      startCy=parseFloat(active.dataset.cy||"0");

      e.preventDefault();
    }

    function onMove(e){
      if(!active) return;
      const dx=e.pageX-startX, dy=e.pageY-startY;
      const newCx=startCx+dx;
      const newCy=startCy+dy;

      const p = docFromCenter(newCx, newCy);
      active.style.left=p.leftDoc+"px";
      active.style.top=p.topDoc+"px";
      active.dataset.cx=newCx;
      active.dataset.cy=newCy;
    }

    function onUp(e){
      if(!active) return;
      active.releasePointerCapture(e.pointerId);
      savePosition(active.dataset.id,
        parseFloat(active.dataset.cx),
        parseFloat(active.dataset.cy)
      );
      active=null;
    }

    document.addEventListener("pointerdown", onDown);
    document.addEventListener("pointermove", onMove);
    document.addEventListener("pointerup", onUp);

 
  });
})();