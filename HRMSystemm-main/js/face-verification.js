(function () {
  const MODEL_URL = '../assets/face-api';
  let modelPromise = null;
  let activeStream = null;
  let session = 0;

  function loadModels() {
    if (!window.faceapi) return Promise.reject(new Error('Face verification library is unavailable.'));
    if (!modelPromise) {
      modelPromise = Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
        faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
        faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL)
      ]);
    }
    return modelPromise;
  }

  function snapshot(video) {
    const canvas = document.createElement('canvas');
    const width = Math.min(720, video.videoWidth || 640);
    const height = Math.round(width * ((video.videoHeight || 480) / (video.videoWidth || 640)));
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d').drawImage(video, 0, 0, width, height);
    return canvas.toDataURL('image/jpeg', .84);
  }

  async function start(video) {
    stop();
    await loadModels();
    const currentSession = ++session;
    const stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 720 } },
      audio: false
    });
    if (currentSession !== session) {
      stream.getTracks().forEach(track => track.stop());
      throw new Error('Face verification was cancelled.');
    }
    activeStream = stream;
    video.srcObject = stream;
    await video.play();
    return stream;
  }

  async function verifyHeadTurn(video, onStatus) {
    const currentSession = session;
    const options = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: .55 });
    const centeredSamples = [];
    let centeredCapture = null;
    const started = Date.now();
    while (currentSession === session && activeStream && Date.now() - started < 30000) {
      const faces = await faceapi.detectAllFaces(video, options).withFaceLandmarks().withFaceDescriptors();
      if (faces.length !== 1) {
        onStatus(faces.length > 1 ? 'Only one employee must be visible.' : 'Center your face inside the camera.');
        await new Promise(resolve => setTimeout(resolve, 180));
        continue;
      }
      const face = faces[0];
      const nose = face.landmarks.getNose();
      const noseTip = nose[Math.min(3, nose.length - 1)];
      const averagePoint = points => ({
        x: points.reduce((sum, point) => sum + point.x, 0) / points.length,
        y: points.reduce((sum, point) => sum + point.y, 0) / points.length
      });
      const leftEye = averagePoint(face.landmarks.getLeftEye());
      const rightEye = averagePoint(face.landmarks.getRightEye());
      const eyeMidX = (leftEye.x + rightEye.x) / 2;
      const eyeDistance = Math.max(1, Math.hypot(leftEye.x - rightEye.x, leftEye.y - rightEye.y));
      const noseRatio = (noseTip.x - eyeMidX) / eyeDistance;
      if (centeredSamples.length < 5) {
        if (Math.abs(noseRatio) > .28) {
          centeredSamples.length = 0;
          onStatus('Look straight at the camera first.');
        } else {
          centeredSamples.push(noseRatio);
          centeredCapture = { descriptor: Array.from(face.descriptor), photo: snapshot(video) };
          onStatus('Hold still and look straight...');
        }
      } else {
        const centerRatio = centeredSamples.reduce((sum, value) => sum + value, 0) / centeredSamples.length;
        onStatus('Now slowly turn your head to your RIGHT.');
        // Horizontal landmark displacement is used instead of a fixed direction sign
        // because front cameras may report mirrored coordinates differently.
        if (Math.abs(noseRatio - centerRatio) >= .12) {
          onStatus('Head movement verified. Face captured successfully.');
          return {
            descriptor: centeredCapture.descriptor,
            photo: centeredCapture.photo,
            livenessVerified: true
          };
        }
      }
      await new Promise(resolve => setTimeout(resolve, 120));
    }
    if (currentSession !== session) throw new Error('Face verification was cancelled.');
    throw new Error('Head movement was not detected. Look straight first, then slowly turn your head to the right.');
  }

  function stop() {
    session++;
    if (activeStream) activeStream.getTracks().forEach(track => track.stop());
    activeStream = null;
  }

  window.FaceVerification = { start, verifyHeadTurn, stop, loadModels };
})();
