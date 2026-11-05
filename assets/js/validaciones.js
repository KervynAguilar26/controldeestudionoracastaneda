// Previsualización de imagen en el registro
document.getElementById('foto_perfil').onchange = function (evt) {
    const [file] = this.files;
    if (file) {
        const preview = document.getElementById('preview');
        preview.innerHTML = `<img src="${URL.createObjectURL(file)}" style="width:100px; height:100px; border-radius:50%; object-fit:cover; border:3px solid var(--dorado-suave);">`;
    }
}