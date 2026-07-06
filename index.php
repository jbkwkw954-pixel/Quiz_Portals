<?php
require_once __DIR__ . '/config.php';
// Nothing secret is echoed into the page. PROCTOR_ENGINE_URL is just a
// localhost address (http://127.0.0.1:5000), not a credential.
$engineUrl = htmlspecialchars(PROCTOR_ENGINE_URL, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Exam Portal</title>
<style>
  :root{
    --bg:#000000; --matrix-green:#00ff41; --matrix-green-dim:#0a3d1a;
    --panel:#0d1117; --panel-2:#111820; --border:#1e2b22; --text:#e6ffe9;
    --text-dim:#7fae8c; --accent:#00ff41; --accent-2:#00c853; --danger:#ff2b3d;
    --warn:#ffb020; --font-mono:'Courier New','Consolas',monospace; --font-ui:'Segoe UI',Arial,sans-serif;
  }
  *{box-sizing:border-box; margin:0; padding:0;}
  html,body{width:100%; height:100%; background:var(--bg); color:var(--text); font-family:var(--font-ui); overflow:hidden; -webkit-user-select:none; user-select:none;}
  #app{position:fixed; inset:0; width:100%; height:100%;}
  #matrixCanvas{position:fixed; inset:0; width:100%; height:100%; z-index:1; background:#000;}
  .screen{position:fixed; inset:0; display:none; align-items:center; justify-content:center; z-index:5; width:100%; height:100%;}
  .screen.active{display:flex;}
  #loginScreen{flex-direction:column;}
  .login-box{position:relative; z-index:6; background:rgba(5,12,8,0.82); border:1px solid var(--matrix-green-dim); box-shadow:0 0 40px rgba(0,255,65,0.15), inset 0 0 60px rgba(0,255,65,0.03); border-radius:6px; padding:48px 44px; width:min(420px,90vw); backdrop-filter:blur(2px); text-align:center; animation:fadeUp .6s ease;}
  @keyframes fadeUp{from{opacity:0; transform:translateY(16px);} to{opacity:1; transform:translateY(0);}}
  .login-title{font-family:var(--font-mono); font-size:28px; letter-spacing:6px; color:var(--matrix-green); text-shadow:0 0 12px rgba(0,255,65,.7), 0 0 2px rgba(0,255,65,.9); margin-bottom:6px;}
  .login-sub{font-family:var(--font-mono); font-size:11px; letter-spacing:3px; color:var(--text-dim); margin-bottom:34px; text-transform:uppercase;}
  .login-sub .blink{animation:blink 1.1s steps(1) infinite;}
  @keyframes blink{50%{opacity:0;}}
  .key-input-wrap{position:relative; margin-bottom:22px;}
  #keyInput{width:100%; background:#000; border:1px solid var(--matrix-green-dim); color:var(--matrix-green); font-family:var(--font-mono); font-size:15px; letter-spacing:2px; padding:14px 16px; border-radius:4px; outline:none; transition:border-color .2s, box-shadow .2s;}
  #keyInput::placeholder{color:#2d5f38; letter-spacing:1px;}
  #keyInput:focus{border-color:var(--matrix-green); box-shadow:0 0 14px rgba(0,255,65,.35);}
  #loginError{color:var(--danger); font-family:var(--font-mono); font-size:12px; height:16px; margin-bottom:10px; letter-spacing:1px;}
  .btn-primary{width:100%; background:linear-gradient(180deg,#0f2e17,#061a0c); border:1px solid var(--matrix-green); color:var(--matrix-green); font-family:var(--font-mono); font-size:14px; letter-spacing:4px; padding:13px 16px; border-radius:4px; cursor:pointer; text-transform:uppercase; transition:all .2s;}
  .btn-primary:hover{background:var(--matrix-green); color:#000; box-shadow:0 0 22px rgba(0,255,65,.55);}
  .btn-primary:active{transform:scale(.98);}
  .btn-primary:disabled{opacity:.5; cursor:not-allowed;}
  .login-hint{position:relative; z-index:6; margin-top:18px; font-family:var(--font-mono); font-size:11px; letter-spacing:2px; color:#2d5f38; text-align:center;}
  #portalShell{position:fixed; inset:0; background:#05070a; z-index:100; display:none; flex-direction:column;}
  #portalShell.active{display:flex;}
  .portal-topbar{height:52px; min-height:52px; background:#0a0f14; border-bottom:1px solid #1a2530; display:flex; align-items:center; justify-content:space-between; padding:0 18px; font-family:var(--font-mono);}
  .portal-brand{color:var(--matrix-green); letter-spacing:3px; font-size:14px; font-weight:bold;}
  .back-btn{background:transparent; border:1px solid #3a4a3f; color:#c7d6cc; padding:7px 14px; border-radius:4px; font-family:var(--font-ui); font-size:13px; cursor:pointer; transition:all .2s;}
  .back-btn:hover{border-color:var(--danger); color:var(--danger);}
  .portal-body{flex:1; overflow:auto; display:flex; align-items:center; justify-content:center; padding:24px; position:relative; min-height:0;}
  .card{background:var(--panel); border:1px solid var(--border); border-radius:10px; padding:40px; width:min(560px,100%); box-shadow:0 10px 40px rgba(0,0,0,.4);}
  .card h2{font-size:22px; color:#fff; margin-bottom:6px; letter-spacing:.5px;}
  .card .sub{color:var(--text-dim); font-size:13px; margin-bottom:28px;}
  .field{margin-bottom:20px; text-align:left;}
  .field label{display:block; font-size:11px; letter-spacing:1.5px; text-transform:uppercase; color:var(--text-dim); margin-bottom:8px;}
  .field input[type=text], .field input[type=email], .field select{width:100%; background:#080c0f; border:1px solid #223028; color:#fff; font-family:var(--font-ui); font-size:15px; padding:12px 14px; border-radius:6px; outline:none; transition:border-color .2s;}
  .field input[type=text]{text-transform:uppercase;}
  .field input:focus, .field select:focus{border-color:var(--accent);}
  .field select{cursor:pointer;}
  .field .err{color:var(--danger); font-size:12px; margin-top:6px; min-height:14px;}
  .btn-row{margin-top:8px;}
  .data-notice{display:flex; align-items:flex-start; gap:10px; background:rgba(0,255,65,.06); border:1px solid rgba(0,255,65,.28); border-radius:8px; padding:12px 14px; font-size:12.5px; color:#c3d1c9; line-height:1.5; margin-bottom:20px; text-align:left;}
  .data-notice .dot{flex-shrink:0; width:8px; height:8px; border-radius:50%; background:var(--accent); margin-top:5px; box-shadow:0 0 8px rgba(0,255,65,.7);}
  .data-notice b{color:var(--accent);}
  .terms-card{width:min(720px,100%); max-height:88vh; display:flex; flex-direction:column;}
  .terms-scroll{overflow-y:auto; padding-right:8px; margin-bottom:20px; border:1px solid var(--border); border-radius:8px; padding:20px 22px; background:#080c0f; max-height:52vh;}
  .terms-scroll h3{font-size:14px; color:var(--accent); margin:16px 0 6px;}
  .terms-scroll h3:first-child{margin-top:0;}
  .terms-scroll p{font-size:13.5px; color:#c3d1c9; line-height:1.6; margin-bottom:4px;}
  .accept-row{display:flex; align-items:center; gap:10px; margin-bottom:18px;}
  .accept-row input{width:16px; height:16px; accent-color:var(--accent);}
  .accept-row label{font-size:13px; color:var(--text-dim);}
  .calib-card{width:min(560px,100%); text-align:center;}
  .calib-video-wrap{position:relative; width:320px; height:320px; margin:0 auto 22px; border-radius:50%; overflow:hidden; border:3px solid var(--accent); box-shadow:0 0 30px rgba(0,255,65,.25); background:#000;}
  #calibPreview{width:100%; height:100%; object-fit:cover;}
  .calib-instruction{font-size:16px; color:#fff; margin-bottom:10px; min-height:24px; font-weight:600;}
  .calib-progress{display:flex; gap:6px; justify-content:center; margin-bottom:20px;}
  .calib-dot{width:60px; height:6px; border-radius:3px; background:#233; transition:background .3s;}
  .calib-dot.done{background:var(--accent);}
  .calib-hint{color:var(--text-dim); font-size:12.5px;}
  .calib-progress-outer{width:100%; height:8px; background:#182018; border-radius:4px; overflow:hidden; margin-bottom:20px;}
  .calib-progress-inner{height:100%; background:linear-gradient(90deg,var(--accent),var(--accent-2)); width:0%; transition:width .2s linear;}
  #quizScreen{width:100%; height:100%; min-height:0;}
  #quizScreenInner{width:100%; height:100%; display:grid; grid-template-columns:220px 1fr 300px; grid-template-rows:100%; gap:18px; padding:18px; min-height:0; box-sizing:border-box;}
  .quiz-panel{background:var(--panel); border:1px solid var(--border); border-radius:10px; padding:18px; display:flex; flex-direction:column; min-height:0; overflow:hidden; box-sizing:border-box;}
  .timer-box{align-items:center; text-align:center; overflow-y:auto;}
  .timer-label{font-size:11px; letter-spacing:2px; color:var(--text-dim); text-transform:uppercase; margin-bottom:10px;}
  .timer-value{font-family:var(--font-mono); font-size:40px; color:var(--accent); text-shadow:0 0 14px rgba(0,255,65,.4); margin-bottom:16px; font-variant-numeric:tabular-nums;}
  .timer-value.low{color:var(--danger); text-shadow:0 0 14px rgba(255,43,61,.5);}
  .progress-outer{width:100%; height:8px; background:#182018; border-radius:4px; overflow:hidden; margin-bottom:20px;}
  .progress-inner{height:100%; background:linear-gradient(90deg,var(--accent),var(--accent-2)); width:100%; transition:width 1s linear;}
  .q-nav-title{font-size:11px; color:var(--text-dim); letter-spacing:1px; margin-bottom:10px; text-transform:uppercase;}
  .q-nav-grid{display:grid; grid-template-columns:repeat(5,1fr); gap:6px;}
  .q-nav-dot{aspect-ratio:1; border-radius:4px; background:#182018; border:1px solid #223028; font-size:11px; color:var(--text-dim); display:flex; align-items:center; justify-content:center;}
  .q-nav-dot.answered{background:rgba(0,255,65,.15); border-color:var(--accent); color:var(--accent);}
  .q-nav-dot.current{border-color:#fff; color:#fff;}
  .center-panel{align-items:center; justify-content:flex-start; text-align:center; position:relative; overflow-y:auto;}
  .chances-title{font-size:11px; letter-spacing:2px; color:var(--text-dim); text-transform:uppercase; margin-bottom:8px;}
  .chances-row{display:flex; gap:8px; margin-bottom:24px;}
  .chance-heart{width:22px; height:22px; border-radius:50%; background:#182018; border:1px solid #2a3a2e; display:flex; align-items:center; justify-content:center; font-size:12px; transition:all .3s;}
  .chance-heart.lost{background:rgba(255,43,61,.15); border-color:var(--danger);}
  .chance-heart.lost::after{content:'✕'; color:var(--danger);}
  .chance-heart.ok::after{content:'●'; color:var(--accent); font-size:9px;}
  .q-course-tag{font-size:11px; letter-spacing:1.5px; text-transform:uppercase; color:var(--accent); background:rgba(0,255,65,.08); border:1px solid rgba(0,255,65,.3); padding:4px 12px; border-radius:20px; margin-bottom:16px; display:inline-block;}
  .q-number{font-size:12px; color:var(--text-dim); margin-bottom:10px;}
  .q-text{font-size:20px; color:#fff; margin-bottom:26px; line-height:1.4; padding:0 10px; min-height:56px;}
  .q-options{width:100%; display:flex; flex-direction:column; gap:12px; padding:0 6px;}
  .q-option{text-align:left; background:#0d1410; border:1px solid #223028; color:#dde; padding:14px 16px; border-radius:8px; cursor:pointer; font-size:14.5px; display:flex; gap:12px; align-items:flex-start; transition:all .15s;}
  .q-option .opt-letter{width:24px; height:24px; border-radius:50%; background:#182018; border:1px solid #2a3a2e; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; color:var(--text-dim);}
  .q-option:hover{border-color:var(--accent); background:#101a13;}
  .q-option.selected{border-color:var(--accent); background:rgba(0,255,65,.09);}
  .q-option.selected .opt-letter{background:var(--accent); color:#000; border-color:var(--accent);}
  .quiz-nav-buttons{display:flex; gap:12px; margin-top:auto; padding-top:24px; width:100%; justify-content:center;}
  .btn-secondary{background:transparent; border:1px solid #2a3a2e; color:#c7d6cc; padding:11px 22px; border-radius:6px; cursor:pointer; font-size:13.5px; transition:all .2s;}
  .btn-secondary:hover{border-color:var(--accent); color:var(--accent);}
  .btn-finish{background:var(--accent); color:#000; border:1px solid var(--accent); font-weight:600; padding:11px 26px; border-radius:6px; cursor:pointer; font-size:13.5px;}
  .btn-finish:hover{box-shadow:0 0 18px rgba(0,255,65,.5);}
  .cheat-flash{position:fixed; inset:0; background:rgba(255,0,20,.28); z-index:9999; display:none; align-items:center; justify-content:center; pointer-events:none;}
  .cheat-flash.show{display:flex; animation:cheatPulse .55s ease;}
  @keyframes cheatPulse{0%{opacity:0;} 15%{opacity:1;} 100%{opacity:0;}}
  .cheat-flash-text{font-family:var(--font-mono); font-size:42px; color:#fff; letter-spacing:5px; text-shadow:0 0 30px rgba(255,0,20,.9); font-weight:bold; text-align:center; padding:0 20px;}
  .soft-toast{position:fixed; top:24px; left:50%; transform:translateX(-50%); z-index:9998; background:rgba(10,20,14,.95); border:1px solid var(--accent); color:#fff; padding:14px 26px; border-radius:8px; font-size:15px; font-weight:600; box-shadow:0 0 24px rgba(0,255,65,.3); opacity:0; transition:opacity .3s, transform .3s; pointer-events:none;}
  .soft-toast.show{opacity:1; transform:translateX(-50%) translateY(6px);}
  .soft-toast.warn{border-color:var(--warn); box-shadow:0 0 24px rgba(255,176,32,.3);}
  .cam-panel{align-items:stretch; min-height:0;}
  .cam-title{font-size:11px; letter-spacing:2px; color:var(--text-dim); text-transform:uppercase; margin-bottom:10px; flex-shrink:0;}
  .cam-video-wrap{position:relative; width:100%; flex:1 1 auto; min-height:0; border-radius:8px; overflow:hidden; background:#000; border:1px solid #223028;}
  #quizPreview{position:absolute; inset:0; width:100%; height:100%; object-fit:cover;}
  .cam-status{margin-top:12px; font-size:12px; text-align:center; padding:8px; border-radius:6px; background:rgba(0,255,65,.08); color:var(--accent); border:1px solid rgba(0,255,65,.25); transition:all .3s; flex-shrink:0;}
  .cam-status.bad{background:rgba(255,43,61,.12); color:var(--danger); border-color:rgba(255,43,61,.4);}
  .result-card{width:min(560px,100%); text-align:center;}
  .searching-wrap{padding:60px 20px;}
  .spinner{width:56px; height:56px; border:3px solid #182018; border-top-color:var(--accent); border-radius:50%; margin:0 auto 24px; animation:spin 1s linear infinite;}
  @keyframes spin{to{transform:rotate(360deg);}}
  .searching-text{color:var(--text-dim); font-size:14px; letter-spacing:1px;}
  .result-headline{font-size:34px; font-weight:800; margin-bottom:10px;}
  .result-headline.pass{color:var(--accent); text-shadow:0 0 20px rgba(0,255,65,.4);}
  .result-headline.fail{color:var(--danger);}
  .result-sub{color:var(--text-dim); font-size:14px; margin-bottom:28px;}
  .result-table{width:100%; border-collapse:collapse; margin-bottom:20px; text-align:left;}
  .result-table tr{border-bottom:1px solid var(--border);}
  .result-table td{padding:12px 4px; font-size:14px;}
  .result-table td:first-child{color:var(--text-dim); width:40%;}
  .result-table td:last-child{color:#fff; font-weight:600;}
  .next-info{background:rgba(0,255,65,.06); border:1px solid rgba(0,255,65,.25); border-radius:8px; padding:16px; font-size:13px; color:#c3d1c9; margin-bottom:24px; line-height:1.6; text-align:left;}
  .next-info b{color:var(--accent);}
  .fail-info{background:rgba(255,43,61,.06); border:1px solid rgba(255,43,61,.25); border-radius:8px; padding:16px; font-size:13px; color:#c3d1c9; margin-bottom:24px; line-height:1.6;}
  .email-status-line{font-size:12px; color:var(--text-dim); margin-bottom:16px; letter-spacing:.5px;}
  .email-status-line.sent{color:var(--accent);}
  .email-status-line.err{color:var(--danger);}
  .feedback-card{width:min(600px,100%);}
  .fb-q{margin-bottom:28px; text-align:left;}
  .fb-q-title{font-size:14.5px; color:#fff; margin-bottom:14px; font-weight:600;}
  .fb-scale{display:flex; gap:10px; justify-content:space-between;}
  .fb-opt{flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; cursor:pointer; padding:10px 4px; border-radius:8px; border:1px solid #223028; background:#0d1410; transition:all .15s;}
  .fb-opt .emoji{font-size:22px;}
  .fb-opt .num{font-size:11px; color:var(--text-dim);}
  .fb-opt:hover{border-color:var(--accent);}
  .fb-opt.selected{border-color:var(--accent); background:rgba(0,255,65,.1);}
  .fb-opt.selected .num{color:var(--accent);}
  ::-webkit-scrollbar{width:8px;}
  ::-webkit-scrollbar-track{background:#0a0d0a;}
  ::-webkit-scrollbar-thumb{background:#233a28; border-radius:4px;}
  .hidden{display:none !important;}
  select option{background:#0d1117; color:#fff;}
  @media (max-width:900px){
    #quizScreenInner{grid-template-columns:1fr; grid-template-rows:auto 1fr auto; height:auto; min-height:100%;}
    .cam-video-wrap{min-height:160px; max-height:220px;}
    .timer-box{flex-direction:row; flex-wrap:wrap; gap:12px; align-items:center;}
  }
</style>
</head>
<body>
<div id="app">
  <canvas id="matrixCanvas"></canvas>

  <div id="loginScreen" class="screen active">
    <div class="login-box">
      <div class="login-title">EXAM PORTAL</div>
      <div class="login-sub">SECURE ACCESS TERMINAL<span class="blink">_</span></div>
      <div class="key-input-wrap"><input type="password" id="keyInput" placeholder="Enter your Key" autocomplete="off"></div>
      <div id="loginError"></div>
      <button class="btn-primary" id="loginSubmitBtn">Submit</button>
    </div>
    <div class="login-hint">ONLY FOR REGISTERED APPLICANTS</div>
  </div>

  <div id="portalShell">
    <div class="portal-topbar">
      <div class="portal-brand">EXAM PORTAL</div>
      <button class="back-btn" id="backBtn">← Back / Close Portal</button>
    </div>
    <div class="portal-body" id="portalBody">

      <div class="card" id="regScreen">
        <h2>Candidate Registration</h2>
        <div class="sub">Please fill in your details to begin the examination process.</div>
        <div class="field"><label for="fullNameInput">Full Name</label><input type="text" id="fullNameInput" placeholder="e.g. JOHN DOE"><div class="err" id="fullNameErr"></div></div>
        <div class="field"><label for="courseSelect">Select Course</label>
          <select id="courseSelect">
            <option value="">-- Choose a course --</option>
            <option value="hardware">Introduction to Computer Hardware and Software</option>
            <option value="wordpress">WordPress Fundamentals</option>
          </select>
          <div class="err" id="courseErr"></div>
        </div>
        <div class="field"><label for="emailInput">Gmail Address</label><input type="email" id="emailInput" placeholder="yourname@gmail.com"><div class="err" id="emailErr"></div></div>
        <div class="btn-row"><button class="btn-primary" id="regNextBtn">Next</button></div>
      </div>

      <div class="card terms-card hidden" id="termsScreen">
        <h2>Examination Rules &amp; Terms of Use</h2>
        <div class="sub">Please read carefully before proceeding.</div>
        <div class="terms-scroll">
          <h3>Mandatory Full-Screen Mode</h3><p>Candidates must remain in full-screen mode throughout the examination.</p>
          <h3>AI-Based Proctoring</h3><p>The examination is monitored by a local AI engine that verifies identity, tracks gaze, and listens for anomalous sound near the candidate.</p>
          <h3>Mandatory Calibration</h3><p>Before the examination begins, every candidate must complete a 10-second face calibration and a 10-second ambient audio calibration.</p>
          <h3>Continuous Monitoring</h3><p>The candidate's face must remain visible. A second person, secondary voice, or disruptive sound within roughly a 2-meter radius will be treated as a violation.</p>
          <h3>Violation Policy</h3><p>Exactly five (5) chances are given. The 5th confirmed violation ends the exam immediately as malpractice.</p>
          <h3>Fair Judgment</h3><p>Natural behavior — blinking, breathing, brief glances, background noise below the calibrated threshold — is not penalized.</p>
          <h3>Time Limit</h3><p>The examination is time-bound and will auto-submit when the timer expires.</p>
          <h3>Data &amp; Notifications</h3><p>Your name, course, email, result, and feedback will be sent to Administration.</p>
        </div>
        <div class="data-notice"><div class="dot"></div><div><b>Your data will be sent to Administration.</b> This includes your name, course, email, exam result, and feedback answers.</div></div>
        <div class="accept-row"><input type="checkbox" id="acceptCheckbox"><label for="acceptCheckbox">I have read and agree to the Examination Rules &amp; Terms of Use.</label></div>
        <button class="btn-primary" id="acceptBtn" disabled>Accept &amp; Continue</button>
      </div>

      <div class="card calib-card hidden" id="calibScreen">
        <h2>AI Calibration</h2>
        <div class="sub">The AI engine needs 10 seconds to learn your face, then 10 seconds to learn your room's ambient sound.</div>
        <div class="calib-video-wrap"><img id="calibPreview" alt="camera preview"></div>
        <div class="calib-instruction" id="calibInstruction">Connecting to AI engine…</div>
        <div class="calib-progress-outer"><div class="calib-progress-inner" id="calibProgressBar"></div></div>
        <div class="calib-progress" id="calibDots"><div class="calib-dot" id="dotFace"></div><div class="calib-dot" id="dotAudio"></div></div>
        <div class="calib-hint" id="calibHint">Look straight at the camera and stay still.</div>
      </div>

      <div id="quizScreen" class="hidden" style="width:100%; height:100%;">
        <div id="quizScreenInner">
          <div class="quiz-panel timer-box">
            <div class="timer-label">Time Remaining</div>
            <div class="timer-value" id="timerValue">10:00</div>
            <div class="progress-outer"><div class="progress-inner" id="timerBar"></div></div>
            <div class="q-nav-title">Questions</div>
            <div class="q-nav-grid" id="qNavGrid"></div>
          </div>
          <div class="quiz-panel center-panel">
            <div class="chances-title">Chances Remaining</div>
            <div class="chances-row" id="chancesRow"></div>
            <div class="q-course-tag" id="qCourseTag">Course</div>
            <div class="q-number" id="qNumber">Question 1 of 10</div>
            <div class="q-text" id="qText">Loading question…</div>
            <div class="q-options" id="qOptions"></div>
            <div class="quiz-nav-buttons">
              <button class="btn-secondary" id="prevBtn">Previous</button>
              <button class="btn-secondary" id="nextQBtn">Next</button>
              <button class="btn-finish hidden" id="finishBtn">Finish Exam</button>
            </div>
          </div>
          <div class="quiz-panel cam-panel">
            <div class="cam-title">Live Monitoring</div>
            <div class="cam-video-wrap"><img id="quizPreview" alt="camera preview"></div>
            <div class="cam-status" id="camStatus">Connecting to AI engine…</div>
          </div>
        </div>
      </div>

      <div class="card result-card hidden" id="resultScreen">
        <div id="resultSearching" class="searching-wrap">
          <div class="spinner"></div>
          <div style="font-size:17px; color:#fff; margin-bottom:8px;">Thank you for participating.</div>
          <div class="searching-text" id="searchingText">PLEASE WAIT…</div>
        </div>
        <div id="resultFinal" class="hidden">
          <div class="result-headline" id="resultHeadline">—</div>
          <div class="result-sub" id="resultSub">—</div>
          <table class="result-table">
            <tr><td>Name</td><td id="rName">—</td></tr>
            <tr><td>Course</td><td id="rCourse">—</td></tr>
            <tr><td>Percentage</td><td id="rPercent">—</td></tr>
            <tr><td>Result</td><td id="rResult">—</td></tr>
          </table>
          <div id="passInfo" class="next-info hidden">Your next <b>weekly test</b> will be held on <b>next Monday</b>.</div>
          <div id="failInfo" class="fail-info hidden">Don't be discouraged — review the course material and try again next time.</div>
          <div class="data-notice"><div class="dot"></div><div><b>Your data has been sent to Administration.</b></div></div>
          <div class="email-status-line" id="resultEmailStatus">Your result will be sent with your feedback.</div>
          <button class="btn-primary" id="toFeedbackBtn">Next</button>
        </div>
      </div>

      <div class="card feedback-card hidden" id="feedbackScreen">
        <h2>Quick Feedback</h2>
        <div class="sub">Help us improve — this will only take a moment.</div>
        <div class="fb-q"><div class="fb-q-title">1. How did you find the test?</div><div class="fb-scale" id="fbScale1"></div></div>
        <div class="fb-q"><div class="fb-q-title">2. How was the course environment?</div><div class="fb-scale" id="fbScale2"></div></div>
        <div class="fb-q"><div class="fb-q-title">3. Was the equipment in proper working order?</div><div class="fb-scale" id="fbScale3"></div></div>
        <div class="fb-q"><div class="fb-q-title">4. How well did the instructor teach?</div><div class="fb-scale" id="fbScale4"></div></div>
        <div class="data-notice"><div class="dot"></div><div><b>Your data will be sent to Administration.</b></div></div>
        <div class="email-status-line" id="feedbackEmailStatus"></div>
        <button class="btn-primary" id="feedbackSubmitBtn">Submit Feedback</button>
      </div>

    </div>
  </div>

  <div class="cheat-flash" id="cheatFlash"><div class="cheat-flash-text" id="cheatFlashText">DON'T CHEAT</div></div>
  <div class="soft-toast" id="softToast"></div>
</div>

<script>
/* =========================================================
   EXAM PORTAL — front-end
   Every fact that matters for grading/violations lives on the server
   (PHP) or in the local AI engine (Python). This script only renders
   what those two already decided; it never decides pass/fail/chances
   on its own, so editing this script via DevTools cannot help a
   candidate cheat or fabricate a result.
   ========================================================= */
const PROCTOR_ENGINE_URL = "<?php echo $engineUrl; ?>";

const State = {
  sessionId: null,
  totalQuestions: 0,
  currentIndex: 0,
  answered: [],          // local UI cache only, mirrors server state
  timeLeftSec: 600,
  timerInterval: null,
  statusPollInterval: null,
  previewInterval: null,
  chancesLeft: 5,
  locked: false,
  quizStarted: false
};

/* ---------- MATRIX RAIN BACKGROUND ---------- */
(function matrixRain(){
  const canvas = document.getElementById('matrixCanvas');
  const ctx = canvas.getContext('2d');
  let w,h,columns,drops, fontSize=16;
  const chars="アイウエオカキクケコサシスセソタチツテトナニヌネノ0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
  function resize(){ w=canvas.width=innerWidth; h=canvas.height=innerHeight; columns=Math.floor(w/fontSize); drops=new Array(columns).fill(0).map(()=>Math.floor(Math.random()*-50)); }
  addEventListener('resize',resize); resize();
  function draw(){
    ctx.fillStyle='rgba(0,0,0,0.06)'; ctx.fillRect(0,0,w,h); ctx.font=fontSize+'px monospace';
    for(let i=0;i<columns;i++){
      const t=chars[Math.floor(Math.random()*chars.length)]; const x=i*fontSize, y=drops[i]*fontSize;
      ctx.fillStyle='#c9ffd8'; ctx.fillText(t,x,y);
      ctx.fillStyle='rgba(0,255,65,0.55)'; ctx.fillText(chars[Math.floor(Math.random()*chars.length)],x,y-fontSize);
      if(y>h && Math.random()>0.975) drops[i]=0;
      drops[i]++;
    }
  }
  setInterval(draw,45);
})();

const $ = id => document.getElementById(id);
function showScreen(el){
  document.querySelectorAll('#portalBody > .card, #portalBody > #quizScreen').forEach(s=>s.classList.add('hidden'));
  el.classList.remove('hidden');
}
function speakLocal(text){
  try{ if('speechSynthesis' in window){ const u=new SpeechSynthesisUtterance(text); window.speechSynthesis.speak(u); } }catch(e){}
}
async function api(url, opts){
  const res = await fetch(url, Object.assign({headers:{'Content-Type':'application/json'}}, opts));
  let data = {};
  try{ data = await res.json(); }catch(e){}
  return { ok: res.ok, status: res.status, data };
}
async function engineApi(path, opts){
  const res = await fetch(PROCTOR_ENGINE_URL + path, Object.assign({headers:{'Content-Type':'application/json'}}, opts));
  return res.json();
}

/* ---------- LOGIN (validated server-side; no key lives in this file) ---------- */
async function attemptLogin(){
  const val = $('keyInput').value.trim();
  const err = $('loginError');
  err.textContent = '';
  if(!val){ err.textContent='Please enter your key.'; return; }
  const r = await api('login.php', { method:'POST', body: JSON.stringify({ key: val }) });
  if(!r.ok){ err.textContent = (r.data && r.data.error) || 'Invalid key.'; $('keyInput').value=''; $('keyInput').focus(); return; }
  openPortal();
}
$('loginSubmitBtn').addEventListener('click', attemptLogin);
$('keyInput').addEventListener('keydown', e=>{ if(e.key==='Enter') attemptLogin(); });

/* ---------- PORTAL OPEN/CLOSE ---------- */
function requestFS(){ const el=document.documentElement; const req=el.requestFullscreen||el.webkitRequestFullscreen||el.msRequestFullscreen; if(req) req.call(el).catch(()=>{}); }
function exitFS(){ const ex=document.exitFullscreen||document.webkitExitFullscreen||document.msExitFullscreen; if(document.fullscreenElement && ex) ex.call(document).catch(()=>{}); }

function openPortal(){
  $('loginScreen').classList.remove('active');
  $('portalShell').classList.add('active');
  requestFS();
  resetRegistrationForm();
  showScreen($('regScreen'));
}
function closePortal(){
  stopQuizTimer(); stopPolling();
  exitFS();
  $('portalShell').classList.remove('active');
  $('loginScreen').classList.add('active');
  $('keyInput').value=''; $('loginError').textContent='';
}
$('backBtn').addEventListener('click', ()=>{ if(confirm('Close the portal and return to login?')) closePortal(); });

function resetRegistrationForm(){
  $('fullNameInput').value=''; $('courseSelect').value=''; $('emailInput').value='';
  $('fullNameErr').textContent=''; $('courseErr').textContent=''; $('emailErr').textContent='';
}
$('fullNameInput').addEventListener('input', e=>{ e.target.value = e.target.value.toUpperCase(); });

/* ---------- REGISTRATION (server validates + creates the session) ---------- */
$('regNextBtn').addEventListener('click', async ()=>{
  $('fullNameErr').textContent=''; $('courseErr').textContent=''; $('emailErr').textContent='';
  const name = $('fullNameInput').value.trim();
  const course = $('courseSelect').value;
  const email = $('emailInput').value.trim();

  const r = await api('register.php', { method:'POST', body: JSON.stringify({ name, course, email }) });
  if(!r.ok){
    const errs = (r.data && r.data.errors) || {};
    if(errs.name) $('fullNameErr').textContent = errs.name;
    if(errs.course) $('courseErr').textContent = errs.course;
    if(errs.email) $('emailErr').textContent = errs.email;
    return;
  }
  State.sessionId = r.data.session_id;
  State.totalQuestions = r.data.total_questions;
  showScreen($('termsScreen'));
});

/* ---------- TERMS ---------- */
$('acceptCheckbox').addEventListener('change', e=>{ $('acceptBtn').disabled = !e.target.checked; });
$('acceptBtn').addEventListener('click', ()=>{
  if(!$('acceptCheckbox').checked) return;
  showScreen($('calibScreen'));
  startCalibration();
});

/* ---------- CALIBRATION (owned entirely by the Python AI engine) ---------- */
async function startCalibration(){
  $('calibInstruction').textContent = 'Connecting to AI engine…';
  try{
    await engineApi('/api/start-session', { method:'POST', body: JSON.stringify({ session_id: State.sessionId }) });
  }catch(e){
    $('calibInstruction').textContent = 'Could not reach the local AI engine.';
    $('calibHint').textContent = 'Make sure app.py is running on this machine, then reload.';
    return;
  }
  $('calibPreview').src = PROCTOR_ENGINE_URL + '/api/frame?t=' + Date.now();
  previewLoop();
  calibrationPollLoop();
}

function previewLoop(){
  if(State.previewInterval) clearInterval(State.previewInterval);
  State.previewInterval = setInterval(()=>{
    const img = document.getElementById('calibPreview').classList.contains('hidden') ? null : $('calibPreview');
    const target = !$('calibScreen').classList.contains('hidden') ? $('calibPreview') : $('quizPreview');
    if(target) target.src = PROCTOR_ENGINE_URL + '/api/frame?t=' + Date.now();
  }, 700);
}

async function calibrationPollLoop(){
  let data;
  try{ data = await engineApi('/api/status', { method:'GET' }); }
  catch(e){ setTimeout(calibrationPollLoop, 800); return; }

  const pct = data.calibration_progress || 0;
  $('calibProgressBar').style.width = pct + '%';

  if(data.calibration_stage === 'face'){
    $('calibInstruction').textContent = 'Learning your face — hold still';
    $('calibHint').textContent = `Capturing facial baseline… ${pct}%`;
  } else if(data.calibration_stage === 'audio'){
    $('dotFace').classList.add('done');
    $('calibInstruction').textContent = 'Learning your room\'s ambient sound';
    $('calibHint').textContent = `Please stay quiet… ${pct}%`;
  } else if(data.calibration_stage === 'done' && data.calibrated){
    $('dotFace').classList.add('done'); $('dotAudio').classList.add('done');
    $('calibInstruction').textContent = 'Calibration complete ✓';
    $('calibHint').textContent = 'Starting your exam…';
    setTimeout(beginQuiz, 500);
    return;
  }
  setTimeout(calibrationPollLoop, 500);
}

/* ---------- QUIZ ---------- */
function stopPolling(){
  if(State.statusPollInterval) clearInterval(State.statusPollInterval);
  if(State.previewInterval) clearInterval(State.previewInterval);
}

async function beginQuiz(){
  State.currentIndex = 0;
  State.chancesLeft = 5;
  State.locked = false;
  State.quizStarted = true;
  State.answered = new Array(State.totalQuestions).fill(false);

  showScreen($('quizScreen'));
  renderQNavShell();
  renderChances(5);
  await loadQuestion(0);
  startQuizTimer();
  statusPollLoop();
}

function formatTime(sec){ const m=Math.floor(sec/60).toString().padStart(2,'0'); const s=(sec%60).toString().padStart(2,'0'); return `${m}:${s}`; }

function startQuizTimer(){
  State.timeLeftSec = 600;
  updateTimerUI();
  State.timerInterval = setInterval(()=>{
    if(State.locked) return;
    State.timeLeftSec--;
    if(State.timeLeftSec <= 0){
      State.timeLeftSec = 0; updateTimerUI(); stopQuizTimer();
      finalizeSubmission('time');
      return;
    }
    updateTimerUI();
    if(State.timeLeftSec % 60 === 0){
      const m = State.timeLeftSec/60;
      if(m>0) speakLocal(`${m} minute${m>1?'s':''} remaining`);
    }
  }, 1000);
}
function stopQuizTimer(){ if(State.timerInterval){ clearInterval(State.timerInterval); State.timerInterval=null; } }
function updateTimerUI(){
  $('timerValue').textContent = formatTime(State.timeLeftSec);
  $('timerBar').style.width = (State.timeLeftSec/600*100) + '%';
  $('timerValue').classList.toggle('low', State.timeLeftSec<=60);
}

function renderQNavShell(){
  const grid = $('qNavGrid'); grid.innerHTML='';
  for(let i=0;i<State.totalQuestions;i++){
    const dot = document.createElement('div');
    dot.className='q-nav-dot'; dot.textContent=i+1;
    dot.addEventListener('click', ()=> loadQuestion(i));
    grid.appendChild(dot);
  }
}
function updateQNav(){
  document.querySelectorAll('.q-nav-dot').forEach((d,i)=>{
    d.classList.toggle('answered', !!State.answered[i]);
    d.classList.toggle('current', i===State.currentIndex);
  });
}
function renderChances(n){
  const row = $('chancesRow'); row.innerHTML='';
  for(let i=0;i<5;i++){
    const h = document.createElement('div');
    h.className = 'chance-heart ' + (i < n ? 'ok' : 'lost');
    row.appendChild(h);
  }
}

const LETTERS = ['A','B','C','D'];
async function loadQuestion(index){
  if(State.locked) return;
  State.currentIndex = index;
  const r = await api(`get_question.php?index=${index}`, { method:'GET' });
  if(!r.ok){ return; }
  const q = r.data;
  $('qCourseTag').textContent = q.course_label;
  $('qNumber').textContent = `Question ${q.index+1} of ${q.total}`;
  $('qText').textContent = q.question;

  const wrap = $('qOptions'); wrap.innerHTML='';
  q.options.forEach((opt,i)=>{
    const el = document.createElement('div');
    el.className = 'q-option' + (q.selected===i ? ' selected' : '');
    el.innerHTML = `<div class="opt-letter">${LETTERS[i]}</div><div>${opt}</div>`;
    el.addEventListener('click', async ()=>{
      await api('save_answer.php', { method:'POST', body: JSON.stringify({ index, choice: i }) });
      State.answered[index] = true;
      loadQuestion(index); // re-render with selection
    });
    wrap.appendChild(el);
  });

  $('prevBtn').disabled = index===0; $('prevBtn').style.opacity = index===0?0.4:1;
  const isLast = index === q.total-1;
  $('nextQBtn').classList.toggle('hidden', isLast);
  $('finishBtn').classList.toggle('hidden', !isLast);
  updateQNav();
}
$('prevBtn').addEventListener('click', ()=>{ if(State.currentIndex>0) loadQuestion(State.currentIndex-1); });
$('nextQBtn').addEventListener('click', ()=>{ if(State.currentIndex<State.totalQuestions-1) loadQuestion(State.currentIndex+1); });
$('finishBtn').addEventListener('click', ()=>{ stopQuizTimer(); finalizeSubmission('manual'); });

/* ---------- STATUS POLLING — the AI engine is the sole authority here ---------- */
function statusPollLoop(){
  State.statusPollInterval = setInterval(async ()=>{
    if(State.locked) return;
    let data;
    try{ data = await engineApi('/api/status', { method:'GET' }); }
    catch(e){
      $('camStatus').textContent = 'Lost connection to AI engine!';
      $('camStatus').classList.add('bad');
      return;
    }

    $('camStatus').textContent = 'Face detected — monitoring active';
    $('camStatus').classList.remove('bad');
    if(data.last_alert && data.last_alert.type === 'no_face'){
      $('camStatus').textContent = 'Face not visible — please return to frame';
      $('camStatus').classList.add('bad');
    }

    if(data.chances_left !== State.chancesLeft){
      State.chancesLeft = data.chances_left;
      renderChances(State.chancesLeft);
      if(data.last_alert && data.last_alert.type==='violation'){
        flashCheatWarning(data.last_alert.message);
      }
    }
    if(data.gaze_alert){ showSoftToast(data.gaze_alert.message || "Don't cheat!", 'warn'); }
    if(data.stress_alert){ showSoftToast(data.stress_alert.message || 'Relax, you can do it!', 'ok'); }

    if(data.locked && !State.locked){
      State.locked = true;
      stopQuizTimer();
      const lockResult = await api('block_quiz.php', { method:'POST', body: JSON.stringify({ lock_token: data.lock_token }) });
      finalizeSubmission('violations', lockResult.ok);
    }
  }, 600);
}

function flashCheatWarning(reason){
  const flash = $('cheatFlash');
  $('cheatFlashText').textContent = "DON'T CHEAT";
  flash.classList.add('show');
  speakLocal("Warning");
  setTimeout(()=> flash.classList.remove('show'), 600);
}
let toastTimer = null;
function showSoftToast(msg, kind){
  const t = $('softToast');
  t.textContent = msg;
  t.className = 'soft-toast show' + (kind==='warn' ? ' warn' : '');
  if(toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(()=> t.classList.remove('show'), 2200);
}

/* ---------- RESULT ---------- */
async function finalizeSubmission(reason, alreadyLocked){
  if(State.locked && reason !== 'violations') return;
  State.locked = true;
  stopQuizTimer(); stopPolling();
  await engineApi('/api/stop-session', { method:'POST' }).catch(()=>{});

  showScreen($('resultScreen'));
  $('resultSearching').classList.remove('hidden');
  $('resultFinal').classList.add('hidden');

  if(reason === 'violations'){
    setTimeout(()=> renderFinalResult({ passed:false, reason:'violations', percent:0, correct:0, total:State.totalQuestions }), 2500);
    return;
  }

  const r = await api('submit_quiz.php', { method:'POST', body: JSON.stringify({ reason }) });
  setTimeout(()=> renderFinalResult(r.ok ? r.data : { passed:false, reason, percent:0, correct:0, total:State.totalQuestions }), 3000);
}

function renderFinalResult(d){
  $('resultSearching').classList.add('hidden');
  $('resultFinal').classList.remove('hidden');
  const headline = $('resultHeadline'), sub = $('resultSub');
  if(d.passed){
    headline.textContent='CONGRATULATIONS!'; headline.className='result-headline pass';
    sub.textContent='You have successfully passed the examination.';
  } else {
    headline.textContent='Better luck next time.'; headline.className='result-headline fail';
    sub.textContent = d.reason==='violations' ? 'The examination was terminated due to repeated security violations.'
                     : d.reason==='time' ? 'Time expired before the examination was completed.'
                     : 'You did not meet the passing criteria this time.';
  }
  $('rName').textContent = document.title; // name/course are display-only here; server has authoritative copies
  $('rPercent').textContent = `${d.percent||0}% (${d.correct||0}/${d.total||0})`;
  $('rResult').textContent = d.passed ? 'PASS' : 'FAIL';
  $('passInfo').classList.toggle('hidden', !d.passed);
  $('failInfo').classList.toggle('hidden', d.passed);
}

$('toFeedbackBtn').addEventListener('click', ()=> showScreen($('feedbackScreen')));

/* ---------- FEEDBACK ---------- */
const FB = { q1:0,q2:0,q3:0,q4:0 };
const FB_EMOJIS=['😞','🙁','😐','🙂','😄'];
function buildFeedbackScale(containerId, key){
  const el = $(containerId); el.innerHTML='';
  for(let i=1;i<=5;i++){
    const opt = document.createElement('div'); opt.className='fb-opt';
    opt.innerHTML = `<div class="emoji">${FB_EMOJIS[i-1]}</div><div class="num">${i}</div>`;
    opt.addEventListener('click', ()=>{ FB[key]=i; el.querySelectorAll('.fb-opt').forEach(o=>o.classList.remove('selected')); opt.classList.add('selected'); });
    el.appendChild(opt);
  }
}
buildFeedbackScale('fbScale1','q1'); buildFeedbackScale('fbScale2','q2');
buildFeedbackScale('fbScale3','q3'); buildFeedbackScale('fbScale4','q4');

$('feedbackSubmitBtn').addEventListener('click', async ()=>{
  const btn = $('feedbackSubmitBtn'); const statusEl = $('feedbackEmailStatus');
  btn.disabled = true; statusEl.textContent='Sending your result & feedback to Administration…'; statusEl.className='email-status-line';
  const r = await api('submit_feedback.php', { method:'POST', body: JSON.stringify(FB) });
  if(r.ok){ statusEl.textContent='✓ Sent to Administration successfully.'; statusEl.classList.add('sent'); }
  else { statusEl.textContent='Could not reach the email service. Closing anyway.'; statusEl.classList.add('err'); }
  setTimeout(()=>{ btn.disabled=false; closePortal(); }, r.ok?900:1400);
});

/* ---------- WINDOW / FULLSCREEN AUDIT LOGGING (informational — does not
   deduct chances; the AI engine is the sole authority on chances) ---------- */
document.addEventListener('fullscreenchange', ()=>{
  if(!document.fullscreenElement && State.quizStarted && !State.locked){
    showSoftToast('Please stay in full-screen mode', 'warn');
    requestFS();
  }
});

window.addEventListener('DOMContentLoaded', ()=> $('keyInput').focus());
</script>
</body>
</html>
